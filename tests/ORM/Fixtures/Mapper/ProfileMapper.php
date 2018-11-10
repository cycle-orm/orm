<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM\Tests\Fixtures\Mapper;

use Spiral\ORM\AbstractMapper;
use Spiral\ORM\ORMInterface;
use Spiral\ORM\Schema;
use Zend\Hydrator\HydratorInterface;
use Zend\Hydrator\Reflection;

class ProfileMapper extends AbstractMapper
{
    /**
     * @var HydratorInterface
     */
    private $hydrator;

    public function __construct(ORMInterface $orm)
    {
        parent::__construct($orm);
        $this->hydrator = new Reflection();
    }

    public function make(array $data)
    {
        return $this->hydrator->hydrate($data, new ProfileEntity());
    }

    protected function getFields($entity): array
    {
        $values = $this->hydrator->extract($entity);
        $columns = $this->orm->getSchema()->define(get_class($entity), Schema::COLUMNS);

        return array_intersect_key(
            $values,
            array_flip($columns)
        );
    }

    // todo: in the heap?
    protected function setField($entity, $field, $value)
    {
        $this->hydrator->hydrate([$field => $value], $entity);
    }

    // todo: from the heap? todo: lazy load?
    public function getField($entity, $field)
    {
        // todo: this has to be done faster
        return $this->hydrator->extract($entity)[$field];
    }
}