# Theme

Primary and focus #30B230; hover #2FB22F; canvas #F3F4F6; white bordered panels; inherited font. Existing source uses rounded-lg panels, p-6 padding, gap-8 layout spacing and gray text. Content shadow: 0px 1px 1px #0000000F, 0px 1px 2px #0000001A. Tailwind is scoped to .lmat-styles and preflight is disabled. No additional font or animation system is needed. Other tokens come from Force UI/Tailwind defaults; do not invent overrides.

## tailwind.config.js

```js
import withTW from "@bsf/force-ui/withTW";

/** @type {import('tailwindcss').Config} */
export default withTW({
	mode: 'jit',
	content: [
		'./admin/Settings/Views/src/**/*.{js,jsx}',
		'./modules/wizard/src/**/*.{js,jsx}',
		'node_modules/@bsf/force-ui/dist/force-ui.js'  // Include force-ui content explicitly
	],
	theme: {
		extend: {
			colors: {
				// Override @bsf/force-ui library colors.
				'button-primary': '#30B230',
				'button-primary-hover': '#2FB22F',
				'brand-800': '#30B230',
				'brand-50': '#FAF5FF',
				'border-interactive': '#30B230',
				focus: '#30B230',
				'focus-border': '#2FB22F',
				'toggle-on': '#30B230',
				'toggle-on-border': '#30B230',
				'toggle-on-hover': '#30B230',
				'deactivated': '#EDEDED',
				'installed': '#CFD5D1'
			},
			fontSize: {
				xxs: '0.6875rem', // 11px
			},
			lineHeight: {
				2.6: '0.6875rem', // 11px
			},
			boxShadow: {
				'content-wrapper':
					'0px 1px 1px 0px #0000000F, 0px 1px 2px 0px #0000001A',
			},
		},
	},
	plugins: [],
	corePlugins: {
		preflight: false,
	},
	important: '.lmat-styles',
});


```

## admin/settings/views/src/input.css

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
@import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap');


.lmat-styles {
    margin: 0;
    margin: 0;
    padding: 0;
    background-color: #F3F4F6;
    font-family: inherit;
    min-height: 100vh;
}


#lmat-setup {
    min-width: 100%;
    min-height: 100vh;
    background-color: #F3F4F6;
}
.setup-body {
    /* min-height: 100vh; */
    background-color: #F3F4F6;
    overflow: hidden;
}

.switcher {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

@media (min-width: 768px) {
    .switcher {
        grid-template-columns: 80% 20%;
        gap: 0;
    }
}

.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 1px;
    background-color: #3b82f6;
}

.ready-table {
    width: 100%;
    border-collapse: collapse;
    ;
}

.ready-table-data {
    display: flex;
    flex-direction: column;
    padding: 10px;
}

.ready-table-data:first-child {
    border-top-left-radius: 5px;
    border-top-right-radius: 5px;
    border-top: 1px solid #d1d5db;
    border-left: 1px solid #d1d5db;
    border-right: 1px solid #d1d5db;
}

.ready-table-data:last-child {
    border-bottom: 1px solid #d1d5db;
    border-left: 1px solid #d1d5db;
    border-right: 1px solid #d1d5db;
    border-bottom-left-radius: 5px;
    border-bottom-right-radius: 5px;
}

.ready-table-data:not(:first-child):not(:last-child) {
    border-top: 1px solid #d1d5db;
    border-left: 1px solid #d1d5db;
    border-right: 1px solid #d1d5db;
}

```
