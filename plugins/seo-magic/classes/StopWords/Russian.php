<?php

namespace Grav\Plugin\SEOMagic\StopWords;

class Russian extends \PhpScience\TextRank\Tool\StopWords\Russian
{
    public function __construct($words = null)
    {
        if (!is_null($words)) {
            $this->words = array_merge($this->words, $words);
        }
    }
}