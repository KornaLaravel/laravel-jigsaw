<?php

namespace TightenCo\Jigsaw\View;

use Illuminate\View\Compilers\BladeCompiler as BaseBladeCompiler;

class BladeCompiler extends BaseBladeCompiler
{
    /**
     * Compile the component tags.
     *
     * @param  string  $value
     * @return string
     */
    protected function compileComponentTags($value)
    {
        if (! $this->compilesComponentTags) {
            return $value;
        }

        return (new ComponentTagCompiler(
            $this->classComponentAliases, $this->classComponentNamespaces, $this,
        ))->compile($value);
    }

    /**
     * Compile the "viteRefresh" statements into valid PHP.
     *
     * @return ?string
     */
    protected function compileViteRefresh()
    {
        return '<?php echo vite_refresh(); ?>';
    }
}
