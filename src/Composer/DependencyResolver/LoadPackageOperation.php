<?php


namespace Composer\DependencyResolver;


use Composer\Semver\Constraint\ConstraintInterface;

class LoadPackageOperation
{
    /** @var string */
    private $source;

    /** @var string */
    private $sourceVersion;

    /** @var string */
    private $target;

    /** @var ConstraintInterface */
    private $targetConstraint;

    /** @var int */
    private $levelFoundOn;

    /**
     * @param string $source
     * @param string $sourceVersion
     * @param string $target
     * @param ConstraintInterface $targetConstraint
     * @param int $levelFoundOn
     */
    public function __construct($source, $sourceVersion, $target, ConstraintInterface $targetConstraint, $levelFoundOn)
    {
        $this->source = $source;
        $this->sourceVersion = $sourceVersion;
        $this->target = $target;
        $this->targetConstraint = $targetConstraint;
        $this->levelFoundOn = $levelFoundOn;
    }

    /**
     * @return string
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getSourceVersion()
    {
        return $this->sourceVersion;
    }

    /**
     * @return string
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * @return ConstraintInterface
     */
    public function getTargetConstraint()
    {
        return $this->targetConstraint;
    }

    /**
     * @return int
     */
    public function getLevelFoundOn()
    {
        return $this->levelFoundOn;
    }

    /**
     * @param ConstraintInterface $targetConstraint
     * @return LoadPackageOperation
     */
    public function withTargetConstraint(ConstraintInterface $targetConstraint)
    {
        $new = clone $this;
        $new->targetConstraint = $targetConstraint;

        return $new;
    }

    public function __toString()
    {
        return sprintf('%s@%s -> %s@%s [found on level %d]',
            $this->getSource(),
            $this->getSourceVersion(),
            $this->getTarget(),
            $this->getTargetConstraint()->getPrettyString(),
            $this->getLevelFoundOn()
        );
    }
}
