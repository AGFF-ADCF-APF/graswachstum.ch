<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class English extends \PhpScience\TextRank\Tool\StopWords\English
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}