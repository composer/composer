<?php

namespace Composer\Transfer;

interface TransferInterface
{
    public function download($fileUrl, $io, $progress, $userAgent);
}