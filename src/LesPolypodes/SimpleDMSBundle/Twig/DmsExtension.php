<?php
/**
 * This file is part of the SimpleDMS package.
 *
 * (c) 2015 Les Polypodes
 * Made in Nantes, France - http://lespolypodes.com
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 *
 * File created by ronan@lespolypodes.com
 */

namespace LesPolypodes\SimpleDMSBundle\Twig;

/**
 * Class DmsExtension.
 */
class DmsExtension extends \Twig_Extension
{
    /**
     * @return array
     */
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('formatBytes', array($this, 'formatBytes')),
        );
    }

    /**
     * @return array
     */
    public function getFunctions()
    {
        return [
            'include_raw' =>  new \Twig_Function_Method($this, 'twigIncludeRaw', array('needs_environment' => true, 'is_safe' => array('all'))),
        ];
    }

    public function twigIncludeRaw(\Twig_Environment $env, $template)
    {
        return $env->getLoader()->getSource($template);
    }

    /**
     * Filter for converting bytes to a human-readable format, as Unix command "ls -h" does.
     *
     * @param string|int $bytes           A string or integer number value to format.
     * @param bool       $base2conversion Defines if the conversion has to be strictly performed as binary values or
     *                                    by using a decimal conversion such as 1 KByte = 1000 Bytes.
     *
     * @link https://github.com/twigphp/Twig-extensions/pull/116/files
     *
     * @return string The number converted to human readable representation.
     *
     * @todo: Use Intl-based translations to deal with "11.4" conversion to "11,4" value
     */
    public function formatBytes($bytes, $base2conversion = true)
    {
        $unit = $base2conversion ? 1000 : 1024;
        if ($bytes <= $unit) {
            return $bytes." B";
        }
        $exp = intval((log($bytes) / log($unit)));
        $pre = ($base2conversion ? "kMGTPE" : "KMGTPE");
        $pre = $pre[$exp - 1].($base2conversion ? "" : "i");

        return sprintf("%.1f %sB", $bytes / pow($unit, $exp), $pre);
    }

    /**
     * @param $bytes
     *
     * @return string
     */
    public function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2).' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes.' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes.' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'dms_extension';
    }
}
