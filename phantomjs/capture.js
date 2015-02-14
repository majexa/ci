console.debug(require('system').args);
var page = require('webpage').create();
page.open('http://' + require('system').args[1] + '/', function() {
  page.render(require('system').args[2] + '.png');
  phantom.exit();
});