import postcss from 'postcss';
import legacyTailwind from 'tailwindcss';
import uiTailwind from '@tailwindcss/postcss';
import autoprefixer from 'autoprefixer';

// Keep existing Tailwind 3/PowerGrid pages intact. Only mary.css uses Tailwind 4.
const uiStyles = {
    postcssPlugin: 'abshir-ui-styles',
    async Once(root, { result }) {
        const isUi = result.opts.from?.replaceAll('\\', '/').endsWith('/resources/css/mary.css');
        const plugins = isUi ? [uiTailwind()] : [legacyTailwind(), autoprefixer()];
        const compiled = await postcss(plugins).process(root.clone(), result.opts);

        if (isUi) {
            // The legacy stylesheet is unlayered. Flatten only this isolated
            // library stylesheet, then scope it to explicitly opted-in surfaces.
            compiled.root.walkAtRules('layer', rule => {
                if (rule.nodes) rule.replaceWith(rule.nodes);
                else rule.remove();
            });
            compiled.root.walkRules(rule => {
                // Tailwind theme tokens must inherit from the scope root,
                // not the document root outside this opt-in surface.
                rule.selectors = rule.selectors.map(selector =>
                    selector === ':root' || selector === ':host' ? ':scope' : selector);
            });
            const scope = postcss.atRule({ name: 'scope', params: '(.mary-surface)' });
            for (const node of [...compiled.root.nodes]) {
                if (node.type === 'atrule' && node.name === 'property') continue;
                scope.append(node);
            }
            compiled.root.append(scope);
        }

        root.removeAll();
        root.append(compiled.root.nodes);
        result.messages.push(...compiled.messages);
    },
};

export default { plugins: [uiStyles] };
