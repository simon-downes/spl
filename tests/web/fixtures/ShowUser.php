<?php declare(strict_types=1);

namespace spl\tests\web\fixtures;

use spl\web\Request;

/**
 * Invokable route handler registered by class name.
 */
final class ShowUser {

    public function __invoke(Request $request, string $id): string {
        return "user {$id}";
    }

}
