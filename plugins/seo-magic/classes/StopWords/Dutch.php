<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Dutch extends \PhpScience\TextRank\Tool\StopWords\Dutch
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}

