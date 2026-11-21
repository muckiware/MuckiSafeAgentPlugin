<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiSafeAgent
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiSafeAgentPlugin\Core\Framework\DataAbstractionLayer\Field\Flag;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Flag;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Immutable as ShopwareImmutable;

if (class_exists(ShopwareImmutable::class)) {
    class_alias(ShopwareImmutable::class, __NAMESPACE__ . '\\Immutable');
} else {
    /**
     * Shopware 6.6 metadata fallback. Database triggers in the initial
     * migration enforce the same write-once contract at persistence level.
     */
    final class Immutable extends Flag
    {
        public function parse(): \Generator
        {
            yield 'immutable' => true;
        }
    }
}
