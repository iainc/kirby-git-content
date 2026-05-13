<template>
  <k-panel-inside class="k-git-content-view">
    <k-header>Git Content {{ size }}</k-header>

    <section class="k-section" v-if="helpText">
      <k-box :text="helpText" html="true" theme="info" />
    </section>

    <section class="k-section" v-if="isConflictBranch">
      <p class="k-git-content-conflict-note" data-theme="negative">
        <k-icon type="alert" />
        <span>{{ conflictStatus.text }}</span>
      </p>
    </section>

    <k-section
      v-if="status.files.length"
      :buttons="changeButtons"
      label="Uncommitted changes"
    >
      <k-collection
        :items="statusItems"
        help="Refer to the <a target='_blank' href='https://git-scm.com/docs/git-status#_short_format'>Git documentation</a> on how to interpret the status codes to the right."
      />
    </k-section>

    <k-section
      :buttons="remoteButtons"
      label="Remote synchronization"
    >
      <k-box :text="remoteStatus.text" :theme="remoteStatus.theme" />
    </k-section>

    <k-section
      :buttons="branchButtons"
      :label="`Latest ${log.length} changes on branch »${branch}«`"
    >
      <k-collection :items="commitItems" />
    </k-section>
  </k-panel-inside>
</template>
<script>
import formatDistance from "date-fns/formatDistance";

export default {
  name: "GitContent",
  props: {
    status: {
      type: Object,
    },
    log: {
      type: Array,
      default: [],
    },
    branch: {
      type: String,
      default: "",
    },
    hasIndexLock: {
      type: Boolean,
      default: false,
    },
    disableBranchManagement: {
      type: Boolean,
      default: false,
    },
    helpText: {},
    buttons: {
      type: Object,
      default: () => ({}),
    },
  },
  computed: {
    differsFromRemote() {
      return this.aheadOfOrigin > 0 || this.behindOfOrigin > 0;
    },
    aheadOfOrigin() {
      return typeof this.status.aheadOfOrigin === "number"
        ? this.status.aheadOfOrigin
        : 0;
    },
    behindOfOrigin() {
      return typeof this.status.behindOfOrigin === "number"
        ? this.status.behindOfOrigin
        : 0;
    },
    conflictBranch() {
      return this.status.conflictBranch || {
        isActive: false,
        name: null,
        baseBranch: null,
      };
    },
    isConflictBranch() {
      return this.conflictBranch.isActive === true;
    },
    buttonMap() {
      return {
        revert: true,
        commit: true,
        fetch: true,
        sync: true,
        push: true,
        reset: true,
        createBranch: true,
        switchBranch: true,
        removeIndexLock: true,
        ...this.buttons,
      };
    },
    commitItems() {
      const items = [];

      this.log.forEach((commit) => {
        items.push({
          text: commit.message,
          info:
            this.formatRelative(commit.date)
            + " / "
            + commit.author
            + " / "
            + commit.hash.substr(0, 7),
          link: false,
        });
      });

      return items;
    },
    statusItems() {
      const items = [];

      this.status.files.forEach((file) => {
        items.push({
          text: file.filename,
          info: file.code,
          link: false,
        });
      });

      return items;
    },
    changeButtons() {
      const buttons = [
        {
          key: "revert",
          text: "Revert Changes",
          icon: "undo",
          click: this.revert,
          class: "btn-revert",
        },
        {
          key: "commit",
          text: "Commit Changes",
          icon: "check",
          click: this.commit,
          class: "btn-commit",
        },
      ];

      return buttons.filter((button) => this.buttonMap[button.key]);
    },
    remoteButtons() {
      const buttons = [
        {
        	key: "fetch",
        	text: "Fetch",
        	icon: "refresh",
        	click: this.fetch,
        	class: "btn-fetch",
        },
        {
          key: "sync",
          text: "Sync",
          icon: "sync",
          click: this.sync,
          class: "btn-sync",
        },
        {
          key: "push",
          text: "Push",
          icon: "upload",
          click: this.push,
          class: "btn-push",
        },
      ];

      if (this.differsFromRemote) {
        buttons.unshift({
					key: "reset",
					text: "Reset",
					icon: "undo",
					click: this.reset,
					class: "btn-reset",
				});
      }

      let filteredButtons = buttons.filter((button) => this.buttonMap[button.key]);

      if (this.isConflictBranch) {
        filteredButtons = filteredButtons.filter((button) => button.key === "sync");
      }

      if (this.hasIndexLock) {
        filteredButtons.unshift({
          key: "removeIndexLock",
          text: "Remove Index Lock",
          icon: "unlock",
          click: this.removeIndexLock,
          class: "btn-remove-index-lock",
        });
      }

      return filteredButtons;
    },
    branchButtons() {
      if (this.disableBranchManagement) {
        return [];
      }

      const buttons = [
        {
          key: "switchBranch",
          text: "Switch Branch",
          icon: "split",
          click: this.switchBranch,
          class: "btn-switch",
        },
      ];

      if (!this.isConflictBranch) {
        buttons.unshift({
          key: "createBranch",
          text: "Create Branch",
          icon: "add",
          click: this.createBranch,
          class: "btn-create",
        });
      }

      return buttons.filter((button) => this.buttonMap[button.key]);
    },
    conflictStatus() {
      const baseBranch = this.conflictBranch.baseBranch || "the original branch";

      return {
        text:
          `Conflict branch ${this.branch} is active. `
          + `New commits stay on this branch. `
          + `Resolve it manually and merge it into ${baseBranch}.`,
      };
    },
    remoteStatus() {
      if (!this.status.hasRemote) {
        return {
          text: `No remote branch yet for ${this.branch}. Sync will create origin/${this.branch}.`,
          theme: "notice",
        };
      }

      if (this.aheadOfOrigin === 0 && this.behindOfOrigin === 0) {
        return {
          text: "Your branch is up to date with origin/" + this.branch,
          theme: "positive",
        };
      }

      if (this.aheadOfOrigin > 0 && this.behindOfOrigin > 0) {
        return {
          text:
            `Your branch has diverged from origin/${this.branch} by `
            + `${this.aheadOfOrigin} ahead and ${this.behindOfOrigin} behind.`,
          theme: "notice",
        };
      }

      return {
        text: `Your branch is ${
          this.aheadOfOrigin > 0 ? "ahead" : "behind"
        } of origin/${this.branch} by ${
          this.aheadOfOrigin > 0 ? this.aheadOfOrigin : this.behindOfOrigin
        } commit${
          (this.aheadOfOrigin > 0 ? this.aheadOfOrigin : this.behindOfOrigin) !== 1 ? "s" : ""
        }.`,
        theme: "notice",
      };
    },
  },
  methods: {
    sync: async function () {
      await panel.app.$api.post("/git-content/sync", {
        branch: this.branch,
      });
      this.$reload();
    },
    push: async function () {
      await panel.app.$api.post("/git-content/push");
      this.$reload();
    },
    fetch: async function () {
      await panel.app.$api.post("/git-content/fetch");
      this.$reload();
    },
    removeIndexLock: async function () {
      await panel.app.$api.post("/git-content/remove-index-lock");
      this.$reload();
    },
    revert: async function () {
      this.$dialog("git-content/revert");
    },
		reset: async function () {
      this.$dialog("git-content/reset");
    },
    commit: async function () {
      this.$dialog("git-content/commit");
    },
    switchBranch: async function () {
      this.$dialog("git-content/branch");
    },
    createBranch: async function () {
      this.$dialog("git-content/create-branch");
    },
    formatRelative(date) {
      return formatDistance(new Date(date), new Date(), {
        addSuffix: true,
      });
    },
  },
};
</script>
<style>
.k-git-content-conflict-note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: var(--text-sm);
  line-height: 1.5;
  color: var(--color-red-500);
}

.k-git-content-conflict-note .k-icon {
  flex-shrink: 0;
  margin-top: 0.15rem;
  color: currentColor;
}
</style>
