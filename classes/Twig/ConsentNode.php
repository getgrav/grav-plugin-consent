<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent\Twig;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiled form of {% consent %}.
 *
 * Both branches are captured into strings and handed to the renderer, which
 * decides what actually reaches the page. That indirection is what lets the
 * render mode be a runtime setting rather than a template rewrite: in `cached`
 * mode the real content ships inside an inert <template> for the client to
 * swap in, and in `dynamic` mode it is either emitted directly or left out
 * entirely.
 */
final class ConsentNode extends Node
{
    private static int $counter = 0;

    public function compile(Compiler $compiler): void
    {
        $suffix = '_' . (++self::$counter);

        $compiler
            ->addDebugInfo($this)
            ->write('$consentCategory' . $suffix . ' = ')
            ->subcompile($this->getNode('category'))
            ->raw(";\n");

        if ($this->hasNode('options')) {
            $compiler
                ->write('$consentOptions' . $suffix . ' = ')
                ->subcompile($this->getNode('options'))
                ->raw(";\n");
        } else {
            $compiler->write('$consentOptions' . $suffix . " = [];\n");
        }

        $compiler
            ->write("ob_start();\n")
            ->subcompile($this->getNode('body'))
            ->write('$consentBody' . $suffix . " = ob_get_clean();\n");

        if ($this->hasNode('else')) {
            $compiler
                ->write("ob_start();\n")
                ->subcompile($this->getNode('else'))
                ->write('$consentElse' . $suffix . " = ob_get_clean();\n");
        } else {
            $compiler->write('$consentElse' . $suffix . " = null;\n");
        }

        $compiler
            ->write('echo \Grav\Plugin\Consent\ConsentRenderer::block(')
            ->raw('(string)$consentCategory' . $suffix . ', ')
            ->raw('$consentBody' . $suffix . ', ')
            ->raw('$consentElse' . $suffix . ', ')
            ->raw('(array)$consentOptions' . $suffix)
            ->raw(");\n");
    }
}
