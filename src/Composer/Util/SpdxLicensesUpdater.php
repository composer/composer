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

use Composer\Json\JsonFormatter;

/**
 * The SPDX Licenses Updater scrapes licenses from the spdx website
 * and updates the "res/spdx-licenses.json" file accordingly.
 *
 * The class is used by the update script "bin/update-spdx-licenses".
 */
class SpdxLicensesUpdater
{
    private $licensesUrl = 'http://www.spdx.org/licenses/';

    public function update()
    {
        $json = json_encode($this->getLicenses(), true);
        $prettyJson = JsonFormatter::format($json, true, true);
        file_put_contents(__DIR__ . '/../../../res/spdx-licenses.json', $prettyJson);
    }

    private function getLicenses()
    {
        $licenses = array();

        $dom = new \DOMDocument;
        $dom->loadHTMLFile($this->licensesUrl);

        $xPath = new \DOMXPath($dom);
        $trs = $xPath->query('//table//tbody//tr');

        // iterate over each row in the table
        foreach ($trs as $tr) {
            $tds = $tr->getElementsByTagName('td'); // get the columns in this row

            if ($tds->length < 4) {
                throw new \Exception('Obtaining the license table failed. Wrong table format. Found less than 4 cells in a row.');
            }

            if (trim($tds->item(3)->nodeValue) == 'License Text') {
                $fullname    = trim($tds->item(0)->nodeValue);
                $identifier  = trim($tds->item(1)->nodeValue);
                $osiApproved = ((isset($tds->item(2)->nodeValue) && $tds->item(2)->nodeValue === 'Y')) ? true : false;

                // The license URL is not scraped intentionally to keep json file size low.
                // It's build when requested, see SpdxLicense->getLicenseByIdentifier().
                //$licenseURL = = $tds->item(3)->getAttribute('href');

                $licenses += array($identifier => array($fullname, $osiApproved));
            }
        }

        return $licenses;
    }
}
