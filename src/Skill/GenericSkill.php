<?php

declare(strict_types=1);

namespace MacroLLM\Skill;

/**
 * Concrete implementation of Skill for use with Skill::fromArray() and Skill::create().
 * Subclass GenericSkill when you need custom behavior; use directly for inline/DB-hydrated skills.
 */
final class GenericSkill extends Skill {}
