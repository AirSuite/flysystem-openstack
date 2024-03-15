/*
 * @author Falko Retter <falko@air-suite.com>
 * @copyright 2015-2019 AirSuite Inc.
 */

module.exports = {
  '*.php': ['npm run lint:prettier'],
  '{!(package)*.json,*.code-snippets,.*rc}': ['npm run lint:json:prettier'],
  'package.json': ['npm run lint:prettier'],
};
