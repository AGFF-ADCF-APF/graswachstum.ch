<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Spanish extends \PhpScience\TextRank\Tool\StopWords\Spanish
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}