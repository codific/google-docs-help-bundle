$(document).ready(function () {
    const HELP_CONTENT_MODAL_ID = "dropdown-item-modal";
    const HELP_CONTENT_MODAL_SELECTOR = `#${HELP_CONTENT_MODAL_ID}`;
    const DROPDOWN_HELP_MODAL_BODY_SELECTOR = ".dropdown-item-modal-body";
    const CURRENT_MARKED_SEARCHED_WORD_CLASS = "current-marked";
    const SEARCH_MODAL_BUTTON_SELECTOR = ".search-modal-navigation";
    const NEXT_SEARCH_BUTTON_DATA_ATTRIBUTE = "next";
    const PREVIOUS_SEARCH_BUTTON_DATA_ATTRIBUTE = "prev";
    const MODAL_SEARCH_INPUT_SELECTOR = "#help-modal-search-input";
    const MARKJS_RESULT_SELECTOR = "mark[data-markjs='true']";
    const ENTER_KEY_CODE = 13;
    const SHIFT_KEY_CODE = 16;
    let helpModalMarkedWords = null;
    let currentSearchHelpModalIndex = 0;

    function jumpToOtherMarkedWord() {
        if ($(helpModalMarkedWords).length > 0) {
            let currentMarkedWord = $(helpModalMarkedWords).eq(currentSearchHelpModalIndex);
            $(helpModalMarkedWords).removeClass(CURRENT_MARKED_SEARCHED_WORD_CLASS);
            if ($(currentMarkedWord).length > 0) {
                $(currentMarkedWord).addClass(CURRENT_MARKED_SEARCHED_WORD_CLASS);
                $(currentMarkedWord).get(0).scrollIntoView();
            }
        }
    }

    $(SEARCH_MODAL_BUTTON_SELECTOR).click(function() {
        if ($(helpModalMarkedWords).length === 0) {
            return;
        }

        let action = $(this).attr("data-control");
        if (action === NEXT_SEARCH_BUTTON_DATA_ATTRIBUTE) {
            currentSearchHelpModalIndex++;
        } else {
            currentSearchHelpModalIndex--;
        }

        let shouldGoToLastElement = currentSearchHelpModalIndex < 0
        if (shouldGoToLastElement) {
            currentSearchHelpModalIndex = $(helpModalMarkedWords).length - 1;
        }

        let shouldGoToFirstElement = currentSearchHelpModalIndex > $(helpModalMarkedWords).length - 1
        if (shouldGoToFirstElement) {
            currentSearchHelpModalIndex = 0;
        }

        if ($(MODAL_SEARCH_INPUT_SELECTOR).val().length > 0) {
            jumpToOtherMarkedWord();
        }
    });

    $(MODAL_SEARCH_INPUT_SELECTOR).on("input", function(e) {
        console.log('inputting', e);
        let searchVal = $(this).val();
        $(DROPDOWN_HELP_MODAL_BODY_SELECTOR).unmark({
            done: function () {
                $(DROPDOWN_HELP_MODAL_BODY_SELECTOR).mark(searchVal, {
                    separateWordSearch: true,
                    done: function () {
                        helpModalMarkedWords = $(DROPDOWN_HELP_MODAL_BODY_SELECTOR).find(MARKJS_RESULT_SELECTOR);
                        currentSearchHelpModalIndex = 0;
                        jumpToOtherMarkedWord();
                    }
                });
            }
        });
    });

    $(MODAL_SEARCH_INPUT_SELECTOR).on("keypress", function(e) {
        if (e.which === ENTER_KEY_CODE) {
            $(`${SEARCH_MODAL_BUTTON_SELECTOR}[data-control='${NEXT_SEARCH_BUTTON_DATA_ATTRIBUTE}']`).trigger("click");
        } else if (e.which === SHIFT_KEY_CODE) {
            $(`${SEARCH_MODAL_BUTTON_SELECTOR}[data-control='${PREVIOUS_SEARCH_BUTTON_DATA_ATTRIBUTE}']`).trigger("click");
        }
    });

    $(HELP_CONTENT_MODAL_SELECTOR).on("hidden.bs.modal", function() {
        $(MODAL_SEARCH_INPUT_SELECTOR).val("");
    });
});