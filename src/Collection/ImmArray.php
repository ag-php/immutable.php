<?php
/**
 * The Immutable Array
 *
 * This provides special methods for quickly creating an immutable array,
 * either from any Traversable, or using a C-optimized fromArray() to directly
 * instantiate from. Also includes methods fundamental to functional
 * programming, e.g. map, filter, join, and sort.
 *
 * @package immutable.php
 * @author Joshua Koudys <josh@qaribou.com>
 */

namespace Qaribou\Collection;

use Qaribou\Iterator\SliceIterator;
use Qaribou\Iterator\ConcatIterator;
use SplFixedArray;
use SplHeap;
use SplStack;
use LimitIterator;
use Iterator;
use ArrayAccess;
use Countable;
use JsonSerializable;
use RuntimeException;
use Traversable;

class ImmArray implements Iterator, ArrayAccess, Countable, JsonSerializable
{
    // The fixed array
    private $sfa = null;

    /**
     * Create an immutable array
     *
     * @param Traversable $immute Data guaranteed to be immutable
     */
    private function __construct(Traversable $immute)
    {
        $this->sfa = $immute;
    }

    /**
     * Map elements to a new ImmArray via a callback
     *
     * @param callable $cb Function to map new data
     * @return static
     */
    public function map(callable $cb): self
    {
        $count = count($this);
        $sfa = new SplFixedArray($count);
        for ($i = 0; $i < $count; $i++) {
            $sfa[$i] = $cb($this->sfa[$i], $i, $this);
        }
        return new static($sfa);
    }

    /**
     * forEach, or "walk" the data
     * Exists primarily to provide a consistent interface, though it's seldom
     * any better than a simple php foreach. Mainly useful for chaining.
     * Named walk for historic reasons - forEach is reserved in PHP
     *
     * @param callable $cb Function to call on each element
     * @return static
     */
    public function walk(callable $cb): self
    {
        foreach ($this as $i => $el) {
            $cb($el, $i, $this);
        }
        return $this;
    }

    /**
     * Filter out elements
     *
     * @param callable $cb Function to filter out on false
     * @return static
     */
    public function filter(callable $cb): self
    {
        $count = count($this->sfa);
        $sfa = new SplFixedArray($count);
        $newCount = 0;

        foreach ($this->sfa as $el) {
            if ($cb($el)) {
                $sfa[$newCount++] = $el;
            }
        }

        $sfa->setSize($newCount);
        return new static($sfa);
    }

    /**
     * Reduce to a single value
     *
     * @param callable $cb Callback(
     *     mixed $previous, mixed $current[, mixed $index, mixed $immArray]
     * ):mixed Callback to run reducing function
     * @param mixed $initial Initial value for first argument
     */
    public function reduce(callable $cb, $initial = null)
    {
        foreach ($this->sfa as $i => $el) {
            $initial = $cb($initial, $el, $i, $this);
        }
        return $initial;
    }

    /**
     * Join a set of strings together.
     *
     * @param string $token Main token to put between elements
     * @param string $secondToken If set, $token on left $secondToken on right
     * @return string
     */
    public function join(string $token = ',', string $secondToken = null): string
    {
        $ret = '';
        if ($secondToken) {
            foreach ($this as $el) {
                $ret .= $token . $el . $secondToken;
            }
        } else {
            $this->rewind();
            while ($this->valid()) {
                $ret .= (string) $this->current();
                $this->next();
                if ($this->valid()) {
                    $ret .= $token;
                }
            }
        }
        return $ret;
    }

    /**
     * Take a slice of the array
     *
     * @param int $begin Start index of slice
     * @param int $end End index of slice
     * @return static
     */
    public function slice(int $begin = 0, int $end = null): self
    {
        $it = new SliceIterator($this->sfa, $begin, $end);
        return new static($it);
    }

    /**
     * Concat to the end of this array
     *
     * @param ImmArray,... $ias One or more immutable array to concat to end
     * @return ImmArray
     */
    public function concat(ImmArray ...$ias): self
    {
        // Create as new immutable's iterator
        return new static(new ConcatIterator($this, ...$ias));
    }

    /**
     * Find a single element
     *
     * @param callable $cb The test to run on each element
     * @return mixed The element we found
     */
    public function find(callable $cb)
    {
        foreach ($this->sfa as $i => $el) {
            if ($cb($el, $i, $this)) return $el;
        }
        return null;
    }

    /**
     * Return a new sorted ImmArray
     *
     * @param callable $cb The sort callback
     * @return static
     */
    public function sort(callable $cb = null): self
    {
        if ($cb) {
            return $this->mergeSort($cb);
        }
        return $this->arraySort();
    }

    /**
     * Sort a new ImmArray by filtering through a heap.
     * Tends to run much faster than array or merge sorts, since you're only
     * sorting the pointers, and the sort function is running in a highly
     * optimized space.
     *
     * @param SplHeap $heap The heap to run for sorting
     * @return static
     */
    public function sortHeap(SplHeap $heap): self
    {
        foreach ($this as $item) {
            $heap->insert($item);
        }
        return static::fromItems($heap);
    }

    /**
     * Factory for building ImmArrays from any traversable
     *
     * @return static
     */
    public static function fromItems(Traversable $arr, callable $cb = null): self
    {
        // We can only do it this way if we can count it
        if ($arr instanceof Countable) {
            $sfa = new SplFixedArray(count($arr));
            foreach ($arr as $i => $el) {
                // Apply a mapping function if available
                if ($cb) $sfa[$i] = $cb($el, $i);
                else $sfa[$i] = $el;
            }

            return new static($sfa);
        }

        // If we can't count it, it's simplest to iterate into an array first
        $asArray = iterator_to_array($arr);
        if ($cb) {
          return static::fromArray(array_map($cb, $asArray, array_keys($asArray)));
        }
        return static::fromArray($asArray);
    }

    /**
     * Build from an array
     *
     * @return static
     */
    public static function fromArray(array $arr): self
    {
        return new static(SplFixedArray::fromArray($arr));
    }

    public function toArray(): array
    {
        return $this->sfa->toArray();
    }

    /**
     * Countable
     */
    public function count(): int
    {
        return count($this->sfa);
    }

    /**
     * Iterator
     */
    public function current()
    {
        return $this->sfa->current();
    }

    public function key()
    {
        return $this->sfa->key();
    }

    public function next()
    {
        return $this->sfa->next();
    }

    public function rewind()
    {
        return $this->sfa->rewind();
    }

    public function valid()
    {
        return $this->sfa->valid();
    }

    /**
     * ArrayAccess
     */
    public function offsetExists($offset): boolean
    {
        return $this->sfa->offsetExists($offset);
    }

    public function offsetGet($offset)
    {
        return $this->sfa->offsetGet($offset);
    }

    public function offsetSet($offset, $value)
    {
        throw new RuntimeException('Attempt to mutate immutable ' . __CLASS__ . ' object.');
    }

    public function offsetUnset($offset)
    {
        throw new RuntimeException('Attempt to mutate immutable ' . __CLASS__ . ' object.');
    }

    /**
     * JsonSerializable
     */
    public function jsonSerialize(): array
    {
        return $this->sfa->toArray();
    }

    /**
     * Perform a bottom-up, non-recursive, in-place mergesort.
     * Efficient for very-large objects, and written without recursion
     * since PHP isn't well optimized for large recursion stacks.
     * TODO: Move this out into a trait
     *
     * @param callable $cb The callback for comparison
     * @return static
     */
    protected function mergeSort(callable $cb): self
    {
        $count = count($this);
        $sfa = $this->sfa;
        $result = new SplFixedArray($count);
        for ($k = 1; $k < $count; $k = $k << 1) {
            for ($left = 0; ($left + $k) < $count; $left += $k << 1) {
                $right = $left + $k;
                $rend = min($right + $k, $count);
                $m = $left;
                $i = $left;
                $j = $right;
                while ($i < $right && $j < $rend) {
                    if ($cb($sfa[$i], $sfa[$j]) <= 0) {
                        $result[$m] = $sfa[$i];
                        $i++;
                    } else {
                        $result[$m] = $sfa[$j];
                        $j++;
                    }
                    $m++;
                }
                while ($i < $right) {
                    $result[$m] = $sfa[$i];
                    $i++;
                    $m++;
                }
                while ($j < $rend) {
                    $result[$m] = $sfa[$j];
                    $j++;
                    $m++;
                }
                for ($m = $left; $m < $rend; $m++) {
                    $sfa[$m] = $result[$m];
                }
            }
        }

        return new static($sfa);
    }

    /**
     * A classic quickSort - great for inplace sorting a big fixed array
     * TODO: Move this out into a trait
     *
     * @param callable $cb The callback for comparison
     * @return static
     */
    protected function quickSort(callable $cb): self
    {
        $sfa = new SplFixedArray(count($this));

        // Create an auxiliary stack
        $stack = new SplStack();

        // initialize top of stack
        // push initial values of l and h to stack
        $stack->push([0, count($sfa) - 1]);

        $first = true;
        // Keep popping from stack while is not empty
        while (!$stack->isEmpty()) {
            // Pop h and l
            [$lo, $hi] = $stack->pop();

            // Start our partition iterator on the original data
            $partition = new LimitIterator($first ? $this->sfa : $sfa, $lo, $hi - $lo);
            $ii = $partition->getInnerIterator();

            // Set pivot element at its correct position in sorted array
            $x = $ii[$hi];
            $i = ($lo - 1);

            foreach ($partition as $j => $el) {
                if ($cb($ii[$j], $x) <= 0) {
                    // Bump up the index of the last low hit, and swap
                    $i++;
                    $temp = $sfa[$i];
                    $sfa[$i] = $el;
                    $sfa[$j] = $temp;
                } elseif ($first) {
                    $sfa[$j] = $el;
                }
            }
            $sfa[$hi] = $x;

            // Set the pivot element
            $pivot = $i + 1;
            // Swap the last hi with the second-last hi
            $sfa[$hi] = $sfa[$pivot];
            $sfa[$pivot] = $x;

            // If there are elements on left side of pivot, then push left
            // side to stack
            if ($pivot - 1 > $lo) {
                $stack->push([$lo, $pivot - 1]);
            }

            // If there are elements on right side of pivot, then push right
            // side to stack
            if ($pivot + 1 < $hi) {
                $stack->push([$pivot + 1, $hi]);
            }
        }

        return new static($sfa);
    }

    /**
     * Sort by applying a CallbackHeap and building a new heap
     * Can be efficient for sorting large stored objects.
     *
     * @param callable $cb The comparison callback
     * @return static
     */
    protected function heapSort(callable $cb): self
    {
        $h = new CallbackHeap($cb);
        foreach ($this as $el) {
            $h->insert($el);
        }

        return static::fromItems($h);
    }

    /**
     * Fallback behaviour to use the builtin array sort functions
     *
     * @param callable $cb The callback for comparison
     * @return static
     */
    protected function arraySort(callable $cb = null): self
    {
        $ar = $this->toArray();
        if ($cb) {
            usort($ar, $cb);
        } else {
            sort($ar);
        }
        return static::fromArray($ar);
    }

    /**
     * Check if element exists in collection
     *
     * @param $element
     * @return bool
     */
    public function includes($element)
    {
        foreach ($this as $el) {
            if ($el === $element) return true;
        }
        return false;
    }

    /**
     * Filter out non-unique elements
     *
     * @return static
     */
    public function unique()
    {
        $count = count($this->sfa);
        $unique = new SplFixedArray($count);
        $newCount = 0;
        $lastUnique = null;

        foreach ($this->sfa as $el) {
            for($i = $newCount; $i >= 0; $i--) {
                // going from back to forward to find sequence of duplicates faster
                if ($unique[$i] === $el) {
                    continue 2;
                }
            }
            $unique[$newCount++] = $el;
        }

        $unique->setSize($newCount);
        return new static($unique);
    }
}
