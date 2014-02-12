<?php

namespace Composer\Transfer;

interface TransferInterface
{
    public function download($fileUrl, $options, $io, $progress, $userAgent);

    public function getHeaders();

    public function getErrorCode();
}