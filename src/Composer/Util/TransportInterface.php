<?php

/*
 * Copyright 2015 Alexander Goryachev <alexfoxlost@gmail.com>.
 */

namespace Composer\Util;

/**
 *
 * @author Alexander Goryachev <alexfoxlost@gmail.com>
 */
interface TransportInterface {
    public function get($originUrl, $fileUrl, $additionalOptions = array(), $fileName = null, $progress = true);
    public function getOptions();
    public function getLastHeaders();
}
