<?php
declare(strict_types=1);

namespace Codific\GoogleDocsHelpBundle\Service;

use Google\Service\Docs;
use Google\Service\Docs\Document;
use Google\Service\Docs\InlineObject;
use Google\Service\Docs\ParagraphElement;
use Google\Service\Docs\StructuralElement;
use Google\Service\Docs\Table;
use Google\Service\Docs\TableCell;
use Google\Service\Drive;
use Google_Client;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Create service account
 * Add Google Docs and Drive APIs
 * Generate key for the service account
 * add the json to the repo - google-docs-credentials.json
 */
class GoogleDocsClientService
{
    public const SUBSYSTEM_ADMIN = 'admin';
    public const SUBSYSTEM_CLIENT = 'app';
    private ?Google_Client $googleClient = null;
    private array $documentIds = [];
    private array $documentObjects = [];
    private array $images = [];
    private array $commentsByRoute = [];
    private array $headings = [];
    /**
     * [
     *      'system' => [
     *          'route_name' => [
     *               'heading' => 'Heading text',
     *               'content' => 'Content of the heading'
     *          ]
     *      ]
     * ]
     */
    private array $helpContent = [];
    private string $redisTag = 'google_docs_help';
    private string $currentHelpContent = '';
    private array $listItems = [];
    private array $errors = [];

    /**
     * GoogleDocsClientService's constructor
     * @param TagAwareCacheInterface $redisCache
     * @param bool $enabled
     * @param array $credentials
     * @param array $documents
     */
    public function __construct(
        private TagAwareCacheInterface $redisCache,
        private bool $enabled = true,
        private array $credentials = [],
        private array $documents = []
    ) {
        // The private key contains \n symbols in the .env file,
        // but they are not interpreted as new lines
        // and the private key value is very strict about its formatting and line ends
        // so we need to replace the text \n with an actual new line
        $privateKey = str_replace("\\n", "\n", $this->credentials['private_key']);
        $this->credentials['private_key'] = $privateKey;
    }

    /**
     * Creates a new client
     * @return void
     * @throws \Google\Exception
     */
    private function loadClient(): void
    {
        $client = new Google_Client();
        $client->setApplicationName('Google Docs API integration');
        $client->setScopes([Docs::DOCUMENTS_READONLY, Drive::DRIVE_READONLY]);
        $client->setAuthConfig($this->credentials);
        $client->setAccessType('offline');
        $this->googleClient = $client;
    }

    /**
     * Loads the Google documents
     * @return void
     */
    private function loadDocuments(): void
    {
        try {
            $service = new Docs($this->googleClient);
            foreach ($this->documentIds as $locale => $documents) {
                foreach ($documents as $system => $documentId) {
                    $this->documentObjects[$locale][$system] = ['documentId' => $documentId, 'document' => $service->documents->get($documentId, ['suggestionsViewMode' => 'SUGGESTIONS_INLINE'])];
                }
            }
        }
        catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'permission')) {
                $this->errors[] = "You do not have permission to access the document";
            } else {
                $this->errors[] = $exception->getMessage();
            }
        }
    }

    /**
     * Extracts all images from the documents' list
     * into an associative array
     * ['imageId' => 'image URL']
     * @return void
     */
    private function extractImages(): void
    {
        /* @var $documentObject Document */
        foreach ($this->documentObjects as $locale => $documentObjects) {
            foreach ($documentObjects as $documentObject) {
                /* @var $inlineObject InlineObject */
                foreach ($documentObject['document']->getInlineObjects() as $imageId => $inlineObject) {
                    if ($inlineObject->getInlineObjectProperties()?->getEmbeddedObject()?->getImageProperties()?->getContentUri() !== null) {
                        $uri = $inlineObject->getInlineObjectProperties()?->getEmbeddedObject()?->getImageProperties()?->getContentUri();
                        $type = pathinfo($uri, PATHINFO_EXTENSION);
                        $data = file_get_contents($uri);
                        $imageInBase64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
                        $this->images[$locale][$imageId] = [
                            'data' => $imageInBase64,
                            // if multiplication is skipped, the images appear too small
                            'width' => $inlineObject->getInlineObjectProperties()?->getEmbeddedObject()?->getSize()->getWidth()->getMagnitude() * 1.5,
                            'height' => $inlineObject->getInlineObjectProperties()?->getEmbeddedObject()?->getSize()->getHeight()->getMagnitude() * 1.5,
                        ];
                    }
                }
            }
        }
    }

    /**
     * Loads the document IDs from the env variables
     * @return void
     */
    private function loadDocumentIds(): void
    {
        foreach ($this->documents as $document) {
            $locale = $document['locale'];
            $adminDocumentId = $document['admin_doc_id'];
            $clientDocumentId = $document['client_doc_id'];
            if (!empty($locale)) {
                if (!empty($adminDocumentId)) {
                    $this->documentIds[$locale][self::SUBSYSTEM_ADMIN] = $adminDocumentId;
                }
                if (!empty($clientDocumentId)) {
                    $this->documentIds[$locale][self::SUBSYSTEM_CLIENT] = $clientDocumentId;
                }
            }
        }
    }

    /**
     * Builds the array with associations
     * between route names and help contents
     * @return void
     */
    private function buildHelpStructure(): void
    {
        /* @var $documentObject Document */
        foreach ($this->documentObjects as $locale => $documentObjects) {
            foreach ($documentObjects as $system => $documentObject) {
                $elements = $documentObject['document']->getBody()->getContent();
                $currentHeading = '';
                /* @var $element StructuralElement */
                foreach ($elements as $element) {
                    $isEndOfDocumentReached = $elementArrayIndex === array_key_last($elements);

                    if ($element->getTable() && $currentHeading) {
                        $this->addTableToHelpContent($element->getTable());
                    }
                    foreach ($element->getParagraph()?->getElements() ?? [] as $el) {
                        if ($el->getTextRun()?->getSuggestedInsertionIds() || $el?->getTextRun()?->getSuggestedDeletionIds() || $el?->getTextRun()?->getSuggestedTextStyleChanges()) {
                            continue;
                        }
                        if ($el?->getInlineObjectElement()?->getInlineObjectId() !== null &&
                            isset($this->images[$locale][$el?->getInlineObjectElement()?->getInlineObjectId()])) { // it's an image
                            $this->closeOpenedListItems($element);
                            $this->addImageToHelpContent($locale, $el, $element);
                        } else { // it's a text element - heading or normal paragraph

                            [$elementText, $endWithNewLine] = $this->getElementText($el);
                            if ($elementText || $endWithNewLine) {
                                $addToContent = true;
                                if (
                                    $isEndOfDocumentReached && isset($this->headings[$locale][$documentObject['document']->getDocumentId()][$currentHeading]) ||
                                    (
                                        $currentHeading != $elementText
                                        && isset($this->headings[$locale][$documentObject['document']->getDocumentId()][$elementText])
                                        && str_starts_with($element->getParagraph()?->getParagraphStyle()?->getNamedStyleType() ?? '', 'HEADING_')
                                    )
                                ) {
                                    if (!empty($currentHeading)) {
                                        $routes = $this->getRoutesForHeading($locale, $documentObject['document']->getDocumentId(), $currentHeading);
                                        foreach ($routes as $route) {
                                            if (str_starts_with($route, $system)) {
                                                $this->helpContent[$locale][$system][$route][] = ['heading' => $currentHeading, 'content' => $this->currentHelpContent];
                                            }
                                        }
                                        $this->currentHelpContent = '';
                                    }
                                    $currentHeading = $elementText;
                                    $addToContent = false;
                                }
                                if ($addToContent && $currentHeading) { // filters out the heading itself from being part of the content
                                    $this->appendHelpContent($elementText, $endWithNewLine, $el, $element);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Adds the table to the help content
     * @param Table $table
     * @return void
     */
    private function addTableToHelpContent(Table $table): void
    {
        $tableContent = '<br><table class="table" style="width: 100%">';
        foreach ($table->getTableRows() as $tableRow) {
            $tableContent .= '<tr>';
            foreach ($tableRow->getTableCells() as $tableCell) {
                $cellText = $this->getCellText($tableCell);
                $backgroundRGBColor = $this->getTableBackgroundColorInRGB($tableCell);
                $tableContent .= "<td style='background-color: {$backgroundRGBColor}'>{$cellText}</td>";
            }
            $tableContent .= '</tr>';
        }
        $tableContent .= '</table>';
        $this->currentHelpContent .= $tableContent;
    }

    /**
     * Extracts the background RGB color of the cell
     * @param TableCell $tableCell
     * @return string
     */
    private function getTableBackgroundColorInRGB(TableCell $tableCell): string
    {
        $rgbColor = $tableCell->getTableCellStyle()?->getBackgroundColor()?->getColor()?->getRgbColor();
        if ($rgbColor) {
            $red = $rgbColor->getRed() * 255;
            $green = $rgbColor->getGreen() * 255;
            $blue = $rgbColor->getBlue() * 255;
            return "rgb({$red}, {$green}, {$blue})";
        }
        return 'rgb(255, 255, 255)'; // white
    }

    /**
     * Extracts the cell text from the table cell element
     * @param TableCell $tableCell
     * @return string
     */
    private function getCellText(TableCell $tableCell): string
    {
        $cellText = '';
        if (isset($tableCell->getContent()[0]) && isset($tableCell->getContent()[0]?->getParagraph()?->getElements()[0])) {
            $element = $tableCell->getContent()[0]->getParagraph()->getElements()[0];
            $cellText = $this->getSpecialStyling(trim($element->getTextRun()->getContent()), $element);
        }
        return $cellText;
    }

    /**
     * Checks if there are opened list items and closes them.
     * Currently, this is needed for closing the lists before inserting an image
     * @param StructuralElement $structuralElement
     * @return void
     */
    private function closeOpenedListItems(StructuralElement $structuralElement): void
    {
        if ($structuralElement->getParagraph()?->getBullet()?->getListId() === null && !empty($this->listItems)) {
            $this->appendListItemsToHelpContent();
        }
    }

    /**
     * Adds an image to the current help content
     * @param string $locale
     * @param ParagraphElement $element
     * @param StructuralElement $structuralElement
     * @return void
     */
    private function addImageToHelpContent(string $locale, ParagraphElement $element, StructuralElement $structuralElement): void
    {
        $image = $this->images[$locale][$element?->getInlineObjectElement()?->getInlineObjectId()];
        $alignment = $this->getImageAlignmentStyle($structuralElement);
        $this->currentHelpContent .= "<img src='{$image['data']}' width='{$image['width']}' height='{$image['height']}' referrerPolicy='no-referrer' style='{$alignment}'  />";
    }

    /**
     * Returns the style properties to make an image centered or right aligned
     * @param StructuralElement $structuralElement
     * @return string
     */
    private function getImageAlignmentStyle(StructuralElement $structuralElement): string
    {
        $alignment = $structuralElement->getParagraph()?->getParagraphStyle()?->getAlignment();
        return match ($alignment) {
            'CENTER' => 'display: block; margin: auto; text-align: center;',
            default => ""
        };
    }

    /**
     * Adds the current element text to the current help content
     * @param string $elementText
     * @param bool $endsWithNewLine
     * @param ParagraphElement $paragraphElement
     * @param StructuralElement $structuralElement
     * @return void
     */
    private function appendHelpContent(string $elementText, bool $endsWithNewLine, ParagraphElement $paragraphElement, StructuralElement $structuralElement): void
    {
        if ($structuralElement->getParagraph()?->getBullet()?->getListId() !== null) {
            $this->addListItem($elementText, $endsWithNewLine, $paragraphElement);
            return; // we don't want to add the element text twice
        } else {
            if (!empty($this->listItems)) {
                $this->appendListItemsToHelpContent();
            }
        }
        $elementText = $this->getSpecialStyling($elementText, $paragraphElement, $structuralElement);
        $textToAppend = nl2br($elementText);
        if ($endsWithNewLine) {
            $textToAppend .= '<br/>';
        }
        $this->currentHelpContent .= $textToAppend;
    }

    /**
     * Builds the list items array.
     * Since list items may come in multiple tokens,
     * we need to append to the previous list item until
     * an item with a new line at the end is reached
     * @param string $elementText
     * @param bool $endsWithNewLine
     * @param ParagraphElement $paragraphElement
     * @return void
     */
    private function addListItem(string $elementText, bool $endsWithNewLine, ParagraphElement $paragraphElement): void
    {
        $lastItemIndex = empty($this->listItems) ? 0 : array_key_last($this->listItems);
        $currentItem = $this->listItems[$lastItemIndex] ?? '';
        $currentItem .= $this->getSpecialStyling($elementText, $paragraphElement);
        $this->listItems[$lastItemIndex] = $currentItem;
        if ($endsWithNewLine) {
            // the previous list item has come to an end,
            // creating the next list item index in the array
            $this->listItems[$lastItemIndex + 1] = '';
        }
    }

    /**
     * Returns an array with the trimmed $element text
     * and a flag indicating whether the content ends with a new line or not
     * @param ParagraphElement $element
     * @return array - trimmed content and does content end with new line flag
     */
    private function getElementText(ParagraphElement $element): array
    {
        $content = $element?->getTextRun()?->getContent();
        if($element?->getTextRun()?->getTextStyle()?->getLink()?->getUrl()!=null) {
            $content = "<a href='".$element?->getTextRun()?->getTextStyle()?->getLink()?->getUrl()."' target='_blank'>$content</a>";
        }
        if (is_null($content)) {
            return ['', false];
        }
        $trimmedContent = trim($content, "\n");
        if (str_ends_with($content, "\n")) {
            return [$trimmedContent, true];
        }
        return [$trimmedContent, false];
    }

    /**
     * Applies the special styling for the element (if such) to the element text
     * @param string $elementText
     * @param ParagraphElement $element
     * @param StructuralElement|null $structuralElement
     * @return string
     */
    private function getSpecialStyling(string $elementText, ParagraphElement $element, ?StructuralElement $structuralElement = null): string
    {
        $result = $elementText;
        if ($element->getTextRun()?->getTextStyle()?->getBold() === true) {
            $result = "<b>{$result}</b>";
        }
        if ($element->getTextRun()?->getTextStyle()?->getItalic() === true) {
            $result = "<i>{$result}</i>";
        }
        if ($element->getTextRun()?->getTextStyle()?->getUnderline() === true) {
            $result = "<u>{$result}</u>";
        }
        if ($element->getTextRun()?->getTextStyle()?->getStrikethrough() === true) {
            $result = "<s>{$result}</s>";
        }
        return $this->getTextElementAlignmentWrapper($result, $structuralElement);
    }

    /**
     * Wraps the text with a span and adds styling to move it horizontally to the center/end of the screen
     * @param string $text
     * @param StructuralElement|null $structuralElement
     * @return string
     */
    private function getTextElementAlignmentWrapper(string $text, ?StructuralElement $structuralElement): string
    {
        return match ($structuralElement?->getParagraph()?->getParagraphStyle()?->getAlignment()) {
            'CENTER' => "<span style='display: block; text-align: center'>{$text}</span>",
            'END' => "<span style='display: block; text-align: right'>{$text}</span>",
            default => $text
        };
    }

    /**
     * Extracts the headings from the document
     * @return void
     */
    private function extractHeadings(): void
    {
        /* @var $documentObject Document */
        foreach ($this->documentObjects as $locale => $documentObjects) {
            foreach ($documentObjects as $documentObject) {
                $documentId = $documentObject['document']->getDocumentId();
                $elements = $documentObject['document']->getBody()->getContent();
                // headings and suggestions are both headings (when the suggestion is directly attached to the heading)
                // so we need to match the end of the actual heading with the beginning of the suggestion
                $currentHeadingText = null;
                /* @var $element StructuralElement */
                foreach ($elements as $element) {
                    if (str_starts_with($element->getParagraph()?->getParagraphStyle()?->getNamedStyleType() ?? '', 'HEADING_')) {
                        $headingElements = $element->getParagraph()->getElements();
                        foreach ($headingElements as $el) {
                            $elementText = trim($el?->getTextRun()?->getContent() ?? '');
                            if ($el->getTextRun()?->getSuggestedInsertionIds()) {
                                if ($currentHeadingText && $elementText) {
                                    // transforms the suggestion notation e.g. [admin_index_index, admin_tenant_index] to an array of non-empty, unique, trimmed route names
                                    $elementText = trim($elementText, '[]');
                                    $routes = array_unique(
                                        array_filter(
                                            array_map(
                                                fn($element) => trim($element),
                                                explode(',', $elementText)
                                            ),
                                            fn($element) => strlen($element) > 0)
                                    );
                                    foreach ($routes as $routeName) {
                                        $this->commentsByRoute[$locale][$documentId][$routeName][$currentHeadingText] = $currentHeadingText;
                                    }
                                }
                                continue;
                            }
                            if ($elementText) {
                                $this->headings[$locale][$documentId][$elementText] = $elementText;
                            }
                            $currentHeadingText = $elementText;
                        }
                    }
                }
            }
        }
    }

    /**
     * Tries to retrieve the routes from the passed document and heading.
     * The document ID is also included here allowing the same heading
     * for multiple documents
     * @param string $locale
     * @param string $documentId
     * @param string $currentHeading
     * @return array
     */
    private function getRoutesForHeading(string $locale, string $documentId, string $currentHeading): array
    {
        $routes = [];
        foreach ($this->commentsByRoute[$locale][$documentId] ?? [] as $route => $headings) {
            foreach ($headings as $heading) {
                if (strtolower($currentHeading) == strtolower($heading)) {
                    $routes[] = $route;
                }
            }
        }
        return $routes;
    }

    /**
     * Parses the documents into associative arrays of
     * route names and content
     * @return array
     * @throws \Google\Exception
     */
    private function getParsedDocs(): array
    {
        return $this->redisCache->get($this->redisTag, function (ItemInterface $item) {
            $item->tag($this->redisTag);
            $item->expiresAfter(3600); // 1 hour
            if (!$this->enabled) {
                return [[], []];
            }
            $this->loadDocumentIds();
            $this->loadClient();
            $this->loadDocuments();
            $this->extractHeadings();
            $this->extractImages();
            $this->buildHelpStructure();
            return [$this->errors, $this->helpContent];
        });
    }

    /**
     * Checks if there's a help content for the passed route
     * and returns it
     * @param string $locale
     * @param string $routeName
     * @param string $subSystem
     * @return array
     * @throws \Google\Exception
     */
    public function getHelpContentForRoute(string $locale, string $routeName, string $subSystem): array
    {
        [$errors, $helpContents] = $this->getParsedDocs();
        return [$errors, $helpContents[$locale][$subSystem][$routeName] ?? []];
    }

    /**
     * Returns all help contents
     * @param bool $showWarnings
     * @return array
     * @throws \Google\Exception
     */
    public function getAllHelpContentsByHeading(bool $showWarnings = true): array
    {
        [$errors, $allHelpContents] = $this->getParsedDocs();
        $errors = $showWarnings ? $errors : [];
        $result = [];
        foreach ($allHelpContents as $locale => $helpContents) {
            foreach ($helpContents as $subSystem => $routes) {
                foreach ($routes as $routeName => $contents) {
                    foreach ($contents as $content) {
                        $existingRoutes = $result[$locale][$subSystem][$content['heading']]['routes'] ?? [];
                        $existingRoutes[] = $routeName;
                        $result[$locale][$subSystem][$content['heading']] = ['routes' => $existingRoutes, 'content' => $content['content']];
                    }
                }
            }
        }
        return [$errors, $result];
    }

    /**
     * Clears the Redis cache so help contents can be loaded again
     * @return void
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function clearCache(): void
    {
        $this->redisCache->delete($this->redisTag);
    }

    /**
     * Appends all list items to the help content
     * @return void
     */
    private function appendListItemsToHelpContent(): void
    {
        $this->currentHelpContent .= '<ul>';
        foreach ($this->listItems as $listItem) {
            if ($listItem) {
                $this->currentHelpContent .= "<li>{$listItem}</li>";
            }
        }
        $this->currentHelpContent .= '</ul>';
        $this->listItems = [];
    }
}
