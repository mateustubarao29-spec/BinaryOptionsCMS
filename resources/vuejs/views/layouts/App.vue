<template>
    <div>
      <Trading v-show="showTradingPanel"></Trading>
      <router-view></router-view>
    </div>
</template>

<script>
    import Trading from "../user/Trading";

    export default {
        name: "App",
        components: {
            Trading
        },
        computed: {
            // Keep the trading terminal visible on the first page load as well
            // as after client-side navigation to and from /trading.
            showTradingPanel() {
                return this.$route.path.startsWith('/trading');
            }
        }
    }
</script>

<style scoped>

</style>
