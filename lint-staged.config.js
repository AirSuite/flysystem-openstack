/*
 * @author Falko Retter <falko@air-suite.com>
 * @copyright 2015-2019 AirSuite Inc.
 */

module.exports = {
  '*.php'                                  : ['yarn lint:prettier'],
  '{!(package)*.json,*.code-snippets,.*rc}': ['yarn lint:prettier --parser json'],
  'package.json'                           : ['yarn lint:prettier'],
  '*.md'                                   : ['yarn lint:markdownlint', 'yarn lint:prettier'],
};
