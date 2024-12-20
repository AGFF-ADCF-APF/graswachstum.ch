<?php

namespace Grav\Plugin\SEOMagic;

use Grav\Common\Data\Data;

class SEOData extends Data
{
    public static function getFilename($path)
    {
        return "user-data://seo-magic/$path/data.json";
    }
}