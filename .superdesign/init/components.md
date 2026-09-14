# Shared UI primitives

Settings import Button, Input, Label, Switch, Accordion, Badge, and Container from @bsf/force-ui. Their source is external; node_modules is absent. Reuse that dependency rather than add a new component library. The following existing translation modal header is a local UI reference, not a new implementation.

## modules/page-translation/src/popup-setting-modal/header.js

```js
import { __ } from "@wordpress/i18n";

const SettingModalHeader = ({ setSettingVisibility, hasProviders = true }) => {
    const title = hasProviders
        ? __("Step 1 - Select Translation Provider", 'translate-words')
        : __("Translation Provider Not Configured", 'translate-words');

    return (
        <div className="modal-header">
            <h2>{title}</h2>
            <span className="close" onClick={() => setSettingVisibility(false)}>&times;</span>
        </div>
    );
}

export default SettingModalHeader;

```
