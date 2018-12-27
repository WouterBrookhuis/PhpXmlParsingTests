<?php
/**
 * Created by PhpStorm.
 * User: Brookhuis
 * Date: 27-12-2018
 * Time: 16:52
 */

namespace App;

/**
 * Class SuluXmlParser
 * Marks section and block tags in properties tags with '@type = section' or '@type = block'
 * Also ensures properties are ALWAYS arrays when converted to JSON
 * @package App
 */
class SuluXmlParser extends XmlParser
{
    protected function childNodeAdded(&$parent, $childKey):void
    {
        if($parent['__name'] === 'properties' &&
            ($parent[$childKey]['__name'] === 'section' || $parent[$childKey]['__name'] === 'block'))
        {
            $parent[$childKey]['@type'] = $parent[$childKey]['__name'];
        }
    }

    protected function beforeNodeReturn(&$node): void
    {
        if($node['__name'] === 'properties')
        {
            $node = $this->arrayifyNode($node);
        }
    }
}