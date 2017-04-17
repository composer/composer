<?php
namespace Composer\Console\Helper;

use Symfony\Component\Console\Helper\Table as BaseTable;
use Symfony\Component\Console\Output\OutputInterface;

class Table extends BaseTable
{
    private static $initializedCustomStyles = false;

    /**
     * @inheritdoc
     */
    public function __construct(OutputInterface $output)
    {
        parent::__construct($output);

        if (!self::$initializedCustomStyles) {
            self::initCustomStyles();
        }

        // Set default style
        $this->setStyle('composer-compact');
    }

    /**
     * Init custom composer styles
     */
    private static function initCustomStyles()
    {
        $composerCompact = self::getStyleDefinition('compact');
        $composerCompact->setVerticalBorderChar('');
        $composerCompact->setCellRowContentFormat('%s  ');

        self::setStyleDefinition('composer-compact', $composerCompact);

        self::$initializedCustomStyles = true;

        return array(
            'composer-compact' => $composerCompact,
        );
    }
}
