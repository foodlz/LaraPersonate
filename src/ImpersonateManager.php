<?php

namespace Octopy\Impersonate;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Octopy\Impersonate\Contracts\Storage;
use Octopy\Impersonate\Exceptions\ImpersonateException;
use Octopy\Impersonate\Storage\SessionStorage;
use Octopy\Impersonate\Events\BeginImpersonation;
use Octopy\Impersonate\Events\LeaveImpersonation;
use ReflectionClass;
use Throwable;

final class ImpersonateManager
{
    /**
     * @const string
     */
    public const VERSION = 'v3.0.0';

    /**
     * @var Storage
     */
    protected Storage $storage;

    /**
     * @var StatefulGuard
     */
    protected StatefulGuard $guard;

    /**
     * @var ImpersonateRepository
     */
    protected ImpersonateRepository $repository;

    /**
     * Impersonate constructor.
     */
    public function __construct()
    {
        $this->guard(config(
            'impersonate.guard'
        ));

        $this->storage = new SessionStorage;
        $this->repository = new ImpersonateRepository($this);
    }

    /**
     * @return string
     */
    public function version() : string
    {
        return ImpersonateManager::VERSION;
    }

    /**
     * @return bool
     */
    public function enabled() : bool
    {
        return config('impersonate.enabled', false);
    }

    /**
     * @return Storage
     */
    public function storage() : Storage
    {
        return $this->storage;
    }

    /**
     * Set auth guard.
     *
     * @param  string $guard
     * @return $this
     */
    public function guard(string $guard) : self
    {
        $this->guard = Auth::guard($guard);

        return $this;
    }

    /**
     * @return ImpersonateAuthorization
     */
    public function authorization() : ImpersonateAuthorization
    {
        return App::make('impersonate.authorization');
    }

    /**
     * Check if current user or impersonator is authorized to impersonate.
     *
     * @return bool
     */
    public function authorized() : bool
    {
        return $this->guard->check() && $this->authorization()->checkImpersonator($this->getImpersonator());
    }

    /**
     * Impersonate user.
     *
     * @param  User|int|string $impersonator
     * @param  User|int|string $impersonated
     * @return User
     * @throws ImpersonateException
     */
    public function take(User|int|string $impersonator, User|int|string $impersonated) : User
    {
        if (! $this->guard->check()) {
            throw new ImpersonateException('You must be logged in to impersonate.');
        }

        // When impersonator is not a user, we will try to find it by id.
        if (! $impersonator instanceof User) {
            $impersonator = $this->repository->findUser($impersonator);
        }

        // When impersonated is not a user, we will try to find it by id.
        if (! $impersonated instanceof User) {
            $impersonated = $this->repository->findUser($impersonated);
        }

        // when in impersonation mode, $impersonator set to current impersonator
        if ($this->isInImpersonation()) {
            $impersonator = $this->getImpersonator();
        }

        // check if impersonation is allowed
        if ($this->check($impersonator, $impersonated)) {
            // set impersonator and impersonated to storage
            $this->storage
                ->setImpersonatorIdentifier($impersonator)
                ->setImpersonatedIdentifier($impersonated);

            // then, set impersonator to current user
            $this->guard->login($impersonated);

            event(new BeginImpersonation(
                $impersonator, $impersonated
            ));
        }

        return $impersonated;
    }

    /**
     * Leave impersonation mode.
     *
     * @return bool
     */
    public function leave() : bool
    {
        if ($this->isInImpersonation()) {

            $impersonator = $this->getImpersonator();
            $impersonated = $this->getImpersonated();

            // first, we need set current user to impersonator
            $this->guard->login($impersonator);

            // then, we need to clear storage
            $this->storage->clearStorage();

            event(new LeaveImpersonation(
                $impersonator, $impersonated
            ));
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isInImpersonation() : bool
    {
        return $this->storage->isInImpersonatingMode();
    }

    /**
     * Get current authenticated user.
     *
     * @return Authenticatable|User
     */
    public function getCurrentUser() : Authenticatable|User
    {
        return $this->guard->user();
    }

    /**
     * Get current impersonator.
     *
     * @return User
     */
    public function getImpersonator() : User
    {
        if ($this->isInImpersonation()) {
            return $this->repository->getImpersonatorInStorage();
        }

        return $this->getCurrentUser();
    }

    /**
     * Get current impersonated user.
     *
     * @return User
     */
    public function getImpersonated() : User
    {
        return $this->repository->getImpersonatedInStorage();
    }

    /**
     * Check if impersonation is allowed.
     *
     * @param  User $impersonator
     * @param  User $impersonated
     * @return bool
     * @throws ImpersonateException
     */
    private function check(User $impersonator, User $impersonated) : bool
    {
        if ($impersonator->getAuthIdentifier() === $impersonated->getAuthIdentifier()) {
            throw new ImpersonateException('You cannot impersonate yourself.');
        }

        if (! $this->authorization()->checkImpersonator($impersonator)) {
            throw new ImpersonateException('You don\'t have the ability to impersonate.');
        }

        if (! $this->authorization()->checkImpersonated($impersonated)) {
            throw new ImpersonateException('You can\'t impersonate this user.');
        }

        return true;
    }
}
