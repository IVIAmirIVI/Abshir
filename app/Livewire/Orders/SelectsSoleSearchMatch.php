<?php

namespace App\Livewire\Orders;

trait SelectsSoleSearchMatch
{
    protected function selectSoleSearchMatch(string $query, string $searchProperty, string $matchesProperty, callable $select): void
    {
        // Enter sends the current input, including text not yet sent by debounce.
        $this->{$searchProperty} = $query;
        $hook = 'updated'.ucfirst($searchProperty);
        $this->{$hook}();
        $matches = $this->{$matchesProperty};
        if ($matches->count() === 1) {
            $select($matches->sole()->getKey());
        }
    }
}
