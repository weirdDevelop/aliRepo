<?php
namespace Eb\Identity;
// a comment mentioning eb_org_units must not count
final class X { function f() { return \Database::one("SELECT id FROM eb_org_units WHERE id = 1"); } }
