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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class SolverProblemsException extends \RuntimeException
{
    protected $problems;

    public function __construct(array $problems, array $learnedPool)
    {
        $message = '';
        foreach ($problems as $i => $problem) {
            $message .= '[';
            foreach ($problem as $why) {

                if (is_int($why) && isset($learnedPool[$why])) {
                    $rules = $learnedPool[$why];
                } else {
                    $rules = $why;
                }

                if (isset($rules['packages'])) {
                    $message .= $this->jobToText($rules);
                } else {
                    $message .= '(';
                    foreach ($rules as $rule) {
                        if ($rule instanceof Rule) {
                            if ($rule->getType() == RuleSet::TYPE_LEARNED) {
                                $message .= 'learned: ';
                            }
                            $message .= $rule . ', ';
                        } else {
                            $message .= 'String(' . $rule . '), ';
                        }
                    }
                    $message .= ')';
                }
                $message .= ', ';
            }
            $message .= "]\n";
        }

        parent::__construct($message);
    }

    public function jobToText($job)
    {
        //$output = serialize($job);
        $output = 'Job(cmd='.$job['cmd'].', target='.$job['packageName'].', packages=['.implode(', ', $job['packages']).'])';
        return $output;
    }
}
