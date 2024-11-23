<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Italian extends \PhpScience\TextRank\Tool\StopWords\Italian
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}