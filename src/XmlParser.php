<?php
namespace App;

use Exception;

function pre_dump($var)
{
	//echo '<pre>',htmlspecialchars(print_r($var, True)), '</pre>';
}

class XmlParser
{
	private $handle;
	private $bufferIdx;
	private $buffer;
	private $encoding;
	private $prevChar;
	private $currentChar;
	private $debugLineNumber;
	private $keepMetaInfoInOutput;

    /**
     * @param $filename
     * @param $keepMetaInfoInOutput
     * @return mixed|null
     * @throws Exception
     */
    public function parseFile($filename, $keepMetaInfoInOutput = false)
	{
		// Reset state
		$this->closeFile();
		unset($this->root);
		$this->bufferIdx = 0;
		$this->buffer = '';
		$this->encoding = 'UTF-8';	// TODO: Get from file and handle properly
		$this->prevChar = '';
		$this->currentChar = '';
		$this->debugLineNumber = 0;
		$this->keepMetaInfoInOutput = $keepMetaInfoInOutput;
		
		$this->handle = fopen($filename, 'rb');
		if(!$this->handle)
		{
			$this->error("Unable to open file {$filename}");
		}
		$root = null;
		try
		{
			// We need to fetch the first character, after that parseNode will do the rest
			$this->nextNonSpace();
			$root = $this->parseNode(0);
		}
		finally
		{
			// Make sure we always close the file, any exception just propagates back up to our caller
			$this->closeFile();
		}
		
		if(!$keepMetaInfoInOutput)
		{
			$root = $this->stripMeta($root);
		}

		return $root;
	}

    /**
     * @param $node
     * @return mixed
     */
    protected function arrayifyNode($node)
	{
		// Duplicate child node names, transform into an array like setup
		$newNode = $node;	// Copy that we can edit
		foreach($node as $key => $value)
		{
			if(mb_strpos($key, '__') === 0 || mb_strpos($key, '@') === 0 || mb_strpos($key, '#') === 0)
			{
				// Skip attributes and build-ins
				continue;
			}
			unset($newNode[$key]);
			$newNode[$node[$key]['__index']] = $node[$key];
		}
		return $newNode;
	}

    /**
     *    Strips build-in meta data from a node, like name and child order
     * @param $node
     * @return mixed
     */
    protected function stripMeta($node)
	{
		foreach($node as $key => $value)
		{
			if(mb_strpos($key, '__') === 0 )
			{
				unset($node[$key]);
			}
		}
		return $node;
	}

    /**
     *    If a node contains only text, the node is turned into just the text string
     * @param $node
     * @return mixed|string
     */
    protected function normalizeNode($node)
	{
		if(1 === \count($node) && isset($node['#']))
		{
			return array_values($node)[0];
		}
		return $node;
	}

    /**
     * @param $childIndex
     * @return null
     * @throws Exception
     */
    private function parseNode($childIndex)
	{
		$node = array();
		// <?xml [stuff we don't care about]
		// <name [attr="value"]/>
		// <name [attr="value"]>[node]
		// </name>
		$isMetaNode = False;	// Doctype or XmlDecl
		$isClosingNode = False;
		$this->assertCurrentChar('<');
		$this->nextChar();
		if($this->currentChar === '/')
		{
			$this->nextChar();
			$isClosingNode = True;
		}
		else if($this->currentChar === '?' || $this->currentChar === '!')
		{
			$this->nextChar();
			$isMetaNode = True;
		}
		
		$name = $this->parseName(True);
		$node['__name'] = $name;
		$node['__index'] = $childIndex;
		
		$this->nextOrCurrentNonSpace();

		// Parse optional attributes
		while(!$this->isReservedChar($this->currentChar))
		{
			// Attribute name
			$attrName = '@' . $this->parseName(True);
			//pre_dump($attrName);
			$this->nextOrCurrentNonSpace();
			// Assignment
			$this->assertCurrentChar('=');
			$this->nextNonSpace();
			// Value
			$this->assertCurrentChar('"');
			$attrValue = $this->parseAttributeValue(False);
			$this->assertCurrentChar('"');
			
			if(array_key_exists($attrName, $node))
			{
				$this->error("Duplicate attribute name in node ${name}}");
			}
			
			$node[$attrName] = $attrValue;
			
			$this->nextNonSpace();
		}
		
		if($isClosingNode)
		{
			// Closing node </node> has no text or children so exit here
			$this->assertCurrentChar('>');
            $this->beforeNodeReturn($node);
			return $node;
		}

        if($this->currentChar === '/')
        {
            $this->assertNextChar('>');
            // <node/> variant, no children to parse, return now
            $this->beforeNodeReturn($node);
            return $node;
        }

        if($isMetaNode)
        {
            // This is a meta node that will not be included in the output, so skip ahead to the next node
            if($this->currentChar === '?')
            {
                $this->nextChar();
            }
            $this->assertCurrentChar('>');
            $this->nextNonSpace();
            return $this->parseNode($childIndex);
        }

        if($this->currentChar !== '>')
        {
            $this->error('Unexpected character');
        }

        // Parse text and next node(s)
		$buildAsArray = False;
		$idx = 0;
		$text = '';
		while(($char = $this->nextChar()) !== false)
		{
			// TODO: Escaped characters!
			if($char === '<')
			{
				// Parse following nodes, this will include our own closing node as well! <tag> that one ---> </tag> <---
				$nextNode = $this->parseNode($idx++);
				// Check if it is our closing node so we can end as well
				if($this->isClosingNode($nextNode))
				{
					// This is the point we can remove metadata from our direct child nodes if we were instructed to do so
                    $this->beforeNodeReturn($node);
					if(!$this->keepMetaInfoInOutput)
					{
						foreach($node as $key => $value)
						{
							if(mb_strpos($key, '__') === 0 || mb_strpos($key, '@') === 0)
							{
								// Skip attributes and build-ins
								continue;
							}
							$node[$key] = $this->normalizeNode($this->stripMeta($value));
						}
					}
					// Set text content
					if($text !== '' && !ctype_space($text))
					{
						$node['#'] = $text;
					}
					return $node;
				}
				
				// Else it's just a child we should add
				if(array_key_exists($nextNode['__name'], $node))
				{
					// Duplicate child node names, transform into an array like setup
					$buildAsArray = True;
					$node = $this->arrayifyNode($node);
				}
				
				if(!$buildAsArray)
				{
					// Regular child node stuff
					$node[$nextNode['__name']] = $nextNode;
                    $this->childNodeAdded($node, $nextNode['__name']);
				}
				else
				{
					// Build as array
					$node[] = $nextNode;
                    $this->childNodeAdded($node, $nextNode['__index']);
				}
			}
			else
			{
				$text .= $char;
			}
		}
		
		pre_dump($node);
		$this->error("Unexpected EOF while parsing node ${name}");
		return null;
	}

    /**
     * Parses and returns an attribute value string
     * @param $startWithCurrentChar
     * @return string
     */
    private function parseAttributeValue($startWithCurrentChar): ?string
    {
		$name = '';
		if($startWithCurrentChar)
		{
			$name .= $this->currentChar;
		}
		while(($char = $this->nextChar()) !== false)
		{
			// TODO: Escape sequences!
			if($this->currentChar === '"')
			{
				if($name === '')
				{
					$this->error('Zero length attribute value detected');
				}
				//pre_dump($name);
				return $name;
			}
			$name .= $char;
		}
		// Ran out of file while reading a name, not an allowed failure condition
		$this->error('Unexpected EOF while parsing attribute value');
		return null;
	}

    /**
     * Parses and returns a tag or attribute name
     * @param $startWithCurrentChar
     * @return string
     */
    private function parseName($startWithCurrentChar): ?string
    {
		$name = '';
		if($startWithCurrentChar)
		{
			$name .= $this->currentChar;
		}
		while(($char = $this->nextChar()) !== false)
		{
			if($this->isReservedCharOrWhitespace($char))
			{
				if($name === '')
				{
					$this->error('Zero length name detected');
				}
				//pre_dump($name);
				return $name;
			}
			$name .= $char;
		}
		// Ran out of file while reading a name, not an allowed failure condition
		$this->error('Unexpected EOF while parsing name');
		return null;
	}

    /**
     * @param $node
     * @return bool
     */
    private function isClosingNode($node): bool
    {
		foreach($node as $key => $value)
		{
			if(mb_strpos($key, '__') !== 0)
			{
				// This node has a non-build-in attribute, child or text, so not a closing node
				return False;
			}
		}
		return True;
	}

    /**
     * Goes to the next non-whitespace character
     */
    private function nextNonSpace(): void
    {
		while(($char = $this->nextChar()) !== false)
		{
			if(!ctype_space($char))
			{
				return;
			}
		}
	}

    /**
     * Goes to the next non-whitespace character only if the current character is whitespace
     */
    private function nextOrCurrentNonSpace(): void
    {
		$char = $this->currentChar;
		do
		{
			if(!ctype_space($char))
			{
				return;
			}
		}
		while(($char = $this->nextChar()) !== false);
	}

    /**
     * Checks if the character is reserved or whitespace
     * @param $char
     * @return bool
     */
    private function isReservedCharOrWhitespace($char): bool
    {
		return $this->isReservedChar($char)|| ctype_space($char);
	}

    /**
     * Checks if the character is a reserved character
     * @param $char
     * @return bool
     */
    private function isReservedChar($char): bool
    {
		return $char === '>' ||
		$char === '<' ||
		$char === '&' ||
		$char === '"' ||
		$char === "'" ||
		$char === '=' ||
		$char === '/' ||
		$char === '?';
	}

    /**
     * Asserts that the next character is equal to the given character.
     * Moves to the next character in order to check it.
     * @param $string
     */
    private function assertNextChar($string): void
    {
		$this->nextChar();
		$this->assertCurrentChar($string);
	}

    /**
     * Asserts that the current character is equal to the given character
     * @param $string
     */
	private function assertCurrentChar($string): void
    {
		if($this->currentChar !== $string)
		{
			$this->error("Expected ${string}");
		}
	}

    /**
     * Moves to the next character in the file and returns it.
     * Returns false on EOF.
     * @return bool|string
     */
    private function nextChar()
	{
		// TODO: Filter out comments
		if($this->bufferIdx >= mb_strlen($this->buffer, $this->encoding))
		{
			// Fetch new data from file
			// Lines seem like an alright thing to read and buffer
			if(($this->buffer = fgets($this->handle)) === false)
			{
				// TODO: I don't like mixing return types, find better options!
				return False;
			}
			$this->debugLineNumber++;
			$this->bufferIdx = 0;
		}
		$this->currentChar = mb_substr($this->buffer, $this->bufferIdx++, 1, $this->encoding);
		return $this->currentChar;
	}

    /**
     * Throws an exception with the given message
     * @param $message
     */
	protected function error($message): void
    {
		pre_dump($this);
		throw new \RuntimeException("XML Parsing Error at line '$this->debugLineNumber', character '$this->currentChar': ".$message);
	}

    /**
     * Closes the file handle if it is open
     */
	private function closeFile(): void
    {
		if($this->handle !== null)
		{
			fclose($this->handle);
			unset($this->handle);
		}
	}

    /**
     * Called when a child node is added to a parent node
     * @param $parent
     * @param $childKey "The key where the child node is in the parent"
     */
    protected function childNodeAdded(&$parent, $childKey): void
    {

    }

    /**
     * @param $node
     */
    protected function beforeNodeReturn(&$node): void
    {

    }
}
