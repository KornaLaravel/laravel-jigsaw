<?php

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use TightenCo\Jigsaw\IterableObject;

class IterableObjectTest extends TestCase
{
    #[Test]
    public function item_in_iterable_object_can_be_referenced_as_object_property()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $this->assertEquals(2, $iterable_object->b);
    }

    #[Test]
    public function item_in_iterable_object_can_be_referenced_as_array_element()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $this->assertEquals(2, $iterable_object['b']);
    }

    #[Test]
    public function item_in_iterable_object_can_be_referenced_as_collection_element()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $this->assertEquals(2, $iterable_object->get('b'));
    }

    #[Test]
    public function iterable_object_can_be_iterated_over_like_a_collection()
    {
        $array = [
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ];

        (new IterableObject($array))->each(function ($item, $index) use ($array) {
            $this->assertEquals($array[$index], $item);
        });
    }

    #[Test]
    public function arrays_can_be_made_iterable_objects_when_adding_to_an_iterable_object()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->putIterable('b', ['c' => 3]);

        $this->assertInstanceOf(IterableObject::class, $iterable_object->b);
        $this->assertEquals(3, $iterable_object->b->c);
    }

    #[Test]
    public function collections_can_be_made_iterable_objects_when_adding_to_an_iterable_object()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->putIterable('b', collect(['c' => 3]));

        $this->assertInstanceOf(IterableObject::class, $iterable_object->b);
        $this->assertEquals(3, $iterable_object->b->c);
    }

    #[Test]
    public function non_arrayable_items_are_not_changed_when_adding_with_make_iterable()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->putIterable('b', 'c');

        $this->assertIsString($iterable_object->b);
    }

    #[Test]
    public function objects_that_extend_iterable_object_are_not_changed_when_adding_with_make_iterable()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->putIterable('b', new ExtendsIterableObject(['c' => 3]));

        $this->assertInstanceOf(ExtendsIterableObject::class, $iterable_object->b);
        $this->assertTrue($iterable_object->b instanceof ExtendsIterableObject);
        $this->assertTrue($iterable_object->b instanceof IterableObject);
    }

    #[Test]
    public function item_can_be_added_to_iterable_object_with_dot_notation()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->set('b.c', collect(['d' => 3]));

        $this->assertEquals(3, $iterable_object->b['c']['d']);
    }

    #[Test]
    public function nested_items_added_with_dot_notation_are_themselves_made_iterable()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
        ]);

        $iterable_object->set('b.c', collect(['d' => 3]));

        $this->assertEquals(3, $iterable_object->b->c->d);
        $this->assertTrue($iterable_object->b instanceof IterableObject);
        $this->assertTrue($iterable_object->b->c instanceof IterableObject);
        $this->assertIsInt($iterable_object->b->c->d);
    }

    #[Test]
    public function intermediate_items_that_extend_iterable_object_are_not_changed_when_adding_new_items_with_dot_notation()
    {
        $iterable_object = new IterableObject([
            'a' => 1,
            'b' => new ExtendsIterableObject(['c' => 3]),
        ]);

        $iterable_object->set('b.d', collect(['e' => 4]));

        $this->assertTrue($iterable_object->b instanceof ExtendsIterableObject);
        $this->assertEquals(3, $iterable_object->b->c);
        $this->assertIsInt($iterable_object->b->c);
        $this->assertTrue($iterable_object->b->d instanceof IterableObject);
        $this->assertEquals(4, $iterable_object->b->d->e);
        $this->assertIsInt($iterable_object->b->d->e);
    }
}

class ExtendsIterableObject extends IterableObject {}
