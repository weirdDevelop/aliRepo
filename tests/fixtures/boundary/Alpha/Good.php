<?php
namespace Eb\Alpha;
use Eb\Beta\Api;
use Eb\Beta\Events\Thing;
final class Good { public function x() { return [Api::class, Thing::class, \Eb\Alpha\Good::class]; } }
