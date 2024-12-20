<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class German extends \PhpScience\TextRank\Tool\StopWords\German
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}