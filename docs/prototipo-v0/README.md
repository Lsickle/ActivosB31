# Prototipo de v0 (Vercel)

Aquí va el prototipo de ActivosB31 generado en [v0.app](https://v0.app). Es **referencia visual y de flujo**, no código para integrar tal cual.

## Cómo exportarlo desde v0

1. Abre el chat del prototipo en v0.
2. Arriba a la derecha, abre el menú de la versión (los tres puntos `…` o el botón de código `</>`) y elige **Download ZIP**.
3. Descomprime el ZIP **dentro de esta carpeta** (`docs/prototipo-v0/`), de modo que quede p. ej. `docs/prototipo-v0/app/`, `docs/prototipo-v0/components/`, etc.
4. No hace falta instalar sus dependencias. Si el ZIP trae `node_modules/` o `.next/`, ya están excluidos por `.gitignore`.

Alternativas si no aparece "Download ZIP":
- **Share** → hacer el chat público y pegar el enlace en la conversación con Claude Code, que puede abrirlo en el navegador.
- Instalar el componente con el CLI de shadcn que v0 muestra en el botón "Add to codebase" (`npx shadcn@latest add "https://v0.app/chat/b/..."`) en un directorio temporal, y copiar el resultado aquí.

## Cómo usarlo al desarrollar

- El prototipo de v0 suele usar shadcn/ui con Radix (`asChild`); este repo usa shadcn/ui estilo `base-nova` sobre **Base UI** (prop `render`). Hay que adaptar los componentes, no copiarlos.
- Los datos del prototipo son de ejemplo: no definen el esquema. El esquema se diseña en `.claude/skills/esquema-bd/SKILL.md` y se aprueba antes de migrar.
- Si el prototipo y `docs/modulos.md` se contradicen, se pregunta al usuario.
