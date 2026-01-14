<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Turkish extends \PhpScience\TextRank\Tool\StopWords\Turkish
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}

