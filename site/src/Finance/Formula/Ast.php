<?php
declare(strict_types=1);

namespace App\Finance\Formula;

abstract class Node {}

final class NumberNode extends Node { public function __construct(public readonly float $value) {} }
final class RefNode extends Node    { public function __construct(public readonly string $code) {} }
final class UnaryNode extends Node  { public function __construct(public readonly string $op, public readonly Node $expr) {} }
final class BinaryNode extends Node { public function __construct(public readonly string $op, public readonly Node $left, public readonly Node $right) {} }
final class FuncCallNode extends Node { public function __construct(public readonly string $name, /** @var Node[] */ public readonly array $args) {} }
