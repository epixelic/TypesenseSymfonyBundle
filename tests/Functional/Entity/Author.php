<?php

declare(strict_types=1);

namespace ACSEO\TypesenseBundle\Tests\Functional\Entity;

class Author
{
    private $id;
    private $name;
    private $country;

    public function __construct(string $name, string $country, ?int $id = 99)
    {
        $this->id          = $id;
        $this->name    = $name;
        $this->country = $country;
    }

    /**
     * Get the value of id.
     */
    public function getId()
    {
        return $this->id;
    }

    public function __toString()
    {
        return $this->name;
    }

    /**
     * Get the value of name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the value of name.
     */
    public function setName($name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the value of country.
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * Set the value of country.
     */
    public function setCountry($country): self
    {
        $this->country = $country;

        return $this;
    }
}
