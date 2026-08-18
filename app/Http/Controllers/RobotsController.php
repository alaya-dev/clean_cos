<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        return response(implode("\n", [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /panier',
            'Disallow: /commande',
            'Disallow: /recherche',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
