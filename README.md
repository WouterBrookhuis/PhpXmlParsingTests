# PhpXmlParsingTests
Some testing with parsing Sulu XML templates with various methods.

### Structure
Some important files and directories in this repo:
* **public/Examples** contains test xml files.
* **src/Controller/TestController.php** runs the parser(s) when you go to the site index page.
* **src/SuluXmlLoader.php** is Sulu's LegacyXMLLoader with bits removed to run it here.
* **src/XmlParser.php** is a basic custom xml parser that doesn't really handle much things well.
* **src/SuluXmlParser.php** is a subclass of XmlParser but does some Sulu config specific things.
