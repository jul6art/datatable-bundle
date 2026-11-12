<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Preference\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceScopeInterface;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The preferences stored with Doctrine: one row per account and table — and per scope, when the
 * application declares one ({@see DatatablePreferenceScopeInterface}).
 *
 * Enabled by `datatable.preferences.storage.entity_class`. An account that is not of the configured
 * class (a machine identity, an in-memory user) reads nothing and writes nothing, as the applications'
 * own stores did.
 */
final readonly class DoctrineDatatablePreferenceStore implements DatatablePreferenceStoreInterface
{
    /**
     * @param class-string<AbstractDatatablePreference>   $entityClass
     * @param class-string<UserInterface>                 $userClass
     * @param iterable<DatatablePreferenceScopeInterface> $scopes
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityClass,
        private string $userClass,
        private iterable $scopes = [],
    ) {
    }

    #[\Override]
    public function read(UserInterface $user, string $key): ?string
    {
        return $this->find($user, $key)?->getPayload();
    }

    #[\Override]
    public function write(UserInterface $user, string $key, string $json): void
    {
        if (!$user instanceof $this->userClass) {
            return;
        }

        $manager = $this->manager();
        $preference = $this->find($user, $key);
        if ($preference instanceof AbstractDatatablePreference) {
            $preference->setPayload($json);
        } else {
            $manager->persist(new ($this->entityClass)($user, $key, $json));
        }
        $manager->flush();
    }

    #[\Override]
    public function delete(UserInterface $user, string $key): void
    {
        $preference = $this->find($user, $key);
        if ($preference instanceof AbstractDatatablePreference) {
            $manager = $this->manager();
            $manager->remove($preference);
            $manager->flush();
        }
    }

    private function find(UserInterface $user, string $key): ?AbstractDatatablePreference
    {
        if (!$user instanceof $this->userClass) {
            return null;
        }

        $criteria = ['owner' => $user, 'datatableKey' => $key];
        foreach ($this->scopes as $scope) {
            $criteria = [...$criteria, ...$scope->criteria($user)];
        }

        $preference = $this->manager()->getRepository($this->entityClass)->findOneBy($criteria);

        return $preference instanceof AbstractDatatablePreference ? $preference : null;
    }

    private function manager(): ObjectManager
    {
        return $this->registry->getManagerForClass($this->entityClass)
            ?? throw new \LogicException(\sprintf('No Doctrine manager handles %s: map it as an entity extending %s.', $this->entityClass, AbstractDatatablePreference::class));
    }
}
