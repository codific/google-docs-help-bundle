#!/bin/sh
echo "running code quality checks before you push as $(git config user.name)"
message=$(vendor/bin/phpstan --ansi analyse --configuration phpstan.neon --memory-limit 1G --no-progress)
if [ $? -eq 1 ]
then
  echo "$message"
  exit 1
fi
