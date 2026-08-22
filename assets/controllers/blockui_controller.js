import { Controller } from '@hotwired/stimulus';
import { useBlockable } from '../mixins/blockable';

/**
 * BlockUI Controller — Blocks an element with a loading overlay.
 *
 * Usage (auto-block on connect):
 *   <div data-controller="ui--blockui"
 *        data-ui--blockui-auto-value="true">
 *       …content…
 *   </div>
 *
 * Usage (block on event):
 *   <form data-controller="ui--blockui"
 *         data-action="submit->ui--blockui#blockSelf">
 *       …
 *   </form>
 *
 * Usage (block body):
 *   <button data-controller="ui--blockui"
 *           data-action="click->ui--blockui#blockBody">
 *       Navigate
 *   </button>
 *
 * Programmatic from another controller:
 *   import { useBlockable } from '../mixins/blockable';
 *   useBlockable(this);
 *   const id = this.block(someElement);
 *   this.unblock(id);
 */
export default class extends Controller {
    static values = {
        auto: { type: Boolean, default: false },
    };

    connect() {
        useBlockable(this);

        if (this.autoValue) {
            this._autoId = this.block(this.element);
        }
    }

    disconnect() {
        this.unblockAll();
    }

    blockSelf() {
        this._selfId = this.block(this.element);
    }

    unblockSelf() {
        if (this._selfId) {
            this.unblock(this._selfId);
            this._selfId = null;
        }
    }

    blockBody() {
        this._bodyId = this.block(document.body);
    }

    unblockBody() {
        if (this._bodyId) {
            this.unblock(this._bodyId);
            this._bodyId = null;
        }
    }
}
