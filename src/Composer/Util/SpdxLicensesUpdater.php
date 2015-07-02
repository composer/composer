<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

/**
 * The SPDX Licenses Updater scrapes licenses from the spdx website
 * and updates the "res/spdx-licenses.json" file accordingly.
 *
 * The class is used by the update script "bin/update-spdx-licenses".
 */
class SpdxLicensesUpdater
{
    /**
     * @param string $file
     * @param string $url
     */
    public function dumpLicenses($file, $url = 'http://www.spdx.org/licenses/')
    {
        $options = 0;

        if (defined('JSON_PRETTY_PRINT')) {
            $options |= JSON_PRETTY_PRINT;
        }

        if (defined('JSON_UNESCAPED_SLASHES')) {
            $options |= JSON_UNESCAPED_SLASHES;
        }

        $licenses = json_encode($this->getLicenses($url), $options);
        file_put_contents($file, $licenses);
    }

    /**
     * @param string $file
     * @param string $url
     */
    public function dumpExceptions($file, $url = 'http://www.spdx.org/licenses/exceptions-index.html')
    {
        $options = 0;

        if (defined('JSON_PRETTY_PRINT')) {
            $options |= JSON_PRETTY_PRINT;
        }

        if (defined('JSON_UNESCAPED_SLASHES')) {
            $options |= JSON_UNESCAPED_SLASHES;
        }

        $exceptions = json_encode($this->getExceptions($url), $options);
        file_put_contents($file, $exceptions);
    }

    /**
     * @param string $url
     *
     * @return array
     */
    private function getLicenses($url)
    {
        $licenses = array();

        $dom = new \DOMDocument;
        @$dom->loadHTMLFile($url);

        $xPath = new \DOMXPath($dom);
        $trs = $xPath->query('//table//tbody//tr');

        // iterate over each row in the table
        foreach ($trs as $tr) {
            $tds = $tr->getElementsByTagName('td'); // get the columns in this row

            if ($tds->length !== 4) {
                continue;
            }

            if (trim($tds->item(3)->nodeValue) == 'License Text') {
                $fullname    = trim($tds->item(0)->nodeValue);
                $identifier  = trim($tds->item(1)->nodeValue);
                $osiApproved = ((isset($tds->item(2)->nodeValue) && $tds->item(2)->nodeValue === 'Y')) ? true : false;

                // The license URL is not scraped intentionally to keep json file size low.
                // It's build when requested, see SpdxLicense->getLicenseByIdentifier().
                //$licenseURL = $tds->item(3)->getAttribute('href');

                $licenses += array($identifier => array($fullname, $osiApproved));
            }
        }

        return $licenses;
    }

    /**
     * @param string $url
     *
     * @return array
     */
    private function getExceptions($url)
    {
        $exceptions = array();

        $dom = new \DOMDocument;
        @$dom->loadHTMLFile($url);

        $xPath = new \DOMXPath($dom);
        $trs = $xPath->query('//table//tbody//tr');

        // iterate over each row in the table
        foreach ($trs as $tr) {
            $tds = $tr->getElementsByTagName('td'); // get the columns in this row

            if ($tds->length !== 3) {
                continue;
            }

            if (trim($tds->item(2)->nodeValue) == 'License Exception Text') {
                $fullname    = trim($tds->item(0)->nodeValue);
                $identifier  = trim($tds->item(1)->nodeValue);

                // The license URL is not scraped intentionally to keep json file size low.
                // It's build when requested, see SpdxLicense->getLicenseExceptionByIdentifier().
                //$licenseURL = $tds->item(2)->getAttribute('href');

                $exceptions += array($identifier => array($fullname));
            }
        }

        return $exceptions;
    }
}
