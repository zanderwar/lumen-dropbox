<?php

namespace Zanderwar\Dropbox\Http\Middleware;

use Closure;
use Zanderwar\Dropbox\Facades\Dropbox;

class DropboxAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Dropbox::getTokenData() === null) {
            return Dropbox::connect();
        }

        return $next($request);
    }
}
