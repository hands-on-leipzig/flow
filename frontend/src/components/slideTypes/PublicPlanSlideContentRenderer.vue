<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import axios from "axios";

const props = withDefaults(defineProps<{
  content: PublicPlanSlideContent,
  preview: Boolean
}>(), {
  preview: false
});


const loading = ref(false);
const result = ref(null);

function getFormattedDateTime() {
  const now = new Date();

  // Get date components
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0'); // Months are 0-based
  const day = String(now.getDate()).padStart(2, '0');

  // Get time components
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');

  return `${year}-${month}-${day}+${hours}:${minutes}`;
}

const planUrl = computed(() => {
  const baseUrl = 'https://dev.flow.hands-on-technology.org/output/zeitplan.cgi';
  const now = getFormattedDateTime();
  const url = baseUrl + `?output=slide&plan=${props.content.planId}`
      + `&hours=${props.content.hours}`
      + `&role=${props.content.role}`
      + `&brief=no`
      + '&now=2026-02-27+12:00'; // <-- testing
  console.log(url);
  return url;
});

const data = "<html lang=\"de\"><head>\r\n\t\t<meta http-equiv=\"Content-Type\" content=\"text\/html; charset=utf-8\">\r\n\t\t<meta charset=\"utf-8\">\r\n\t\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\r\n\t\t<meta name=\"description\" content=\"\">\r\n\t\t<meta name=\"author\" content=\"Hands on Technology e.V.\">\r\n\t\t<meta name=\"generator\" content=\"...\">\r\n\t\t<title>FLL Regionalwettbewerb Braunschweig<\/title>\r\n\t\t<link href=\"https:\/\/cdn.jsdelivr.net\/npm\/bootstrap@5.3.3\/dist\/css\/bootstrap.min.css\" rel=\"stylesheet\" integrity=\"sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH\" crossorigin=\"anonymous\">\r\n\t\t<style>\r\n\t\t\t.bd-placeholder-img{\r\n\t\t\t\t\tfont-size: 1.125rem;\r\n\t\t\t\t\ttext-anchor: middle;\r\n\t\t\t\t\t-webkit-user-select: none;\r\n\t\t\t\t\t-moz-user-select: none;\r\n\t\t\t\t\tuser-select: none;\r\n\t\t\t}\r\n                    \r\n\t\t\t@media (min-width : 768px){\r\n\t\t\t\t\t.bd-placeholder-img-lg{\r\n\t\t\t\t\t\tfont-size: 3.5rem;\r\n\t\t\t\t\t}\r\n\t\t\t}\r\n\t\t<\/style>\r\n\t\t<link rel=\"stylesheet\" href=\"https:\/\/cdn.jsdelivr.net\/npm\/bootstrap-icons@1.10.2\/font\/bootstrap-icons.css\">\r\n\t\t<!--\r\n\t\t<link href=\"css\/starter-template.css\" rel=\"stylesheet\">\r\n\t\t<link href=\"css\/fll.css\" rel=\"stylesheet\">\r\n\t\t-->\r\n\t\t<script src=\"https:\/\/code.jquery.com\/jquery-3.6.0.min.js\" integrity=\"sha256-\/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej\/m4=\" crossorigin=\"anonymous\"><\/script>\r\n\t\t<script src=\"https:\/\/code.jquery.com\/ui\/1.13.2\/jquery-ui.js\"><\/script>\r\n\t\t<script src=\"https:\/\/cdn.jsdelivr.net\/npm\/bootstrap@5.3.3\/dist\/js\/bootstrap.bundle.min.js\" integrity=\"sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz\" crossorigin=\"anonymous\"><\/script>\r\n\t<\/head>\r\n\t<body style=\"background: transparent\">\r\n\t\t<main class=\"flex-shrink-0\">\r\n\t\t\t<div class=\"container-fluid\">\r\n\t\t\t\t<!--\r\n\t\t\t\t<h1>FIRST LEGO League Zeitplan<\/h1>\r\n\t\t\t\t-->\r\n\t\t\t\t\r\n\t\t\t\t\r\n<div class=\"row\" style=\"padding-top:20px\">\r\n    <div class=\"col-12\">\r\n        <p style=\"text-align:center; font-size:clamp(15px, 6vw, 37px); font-weight:bold; color:#24355C\">\r\n            Programmpunkte, die gerade laufen oder in sp\u00E4testens 2 Stunden starten\r\n        <\/p>\r\n    <\/div>\r\n<\/div>\r\n\r\n\r\n<div class=\"container\" style=\"margin-top:30px\">\r\n    <div class=\"row row-cols-1 row-cols-md-4 g-4\">\r\n        <div class=\"col\" id=\"486989\">\r\n                                        <div class=\"card h-100\">\r\n                                            <div class=\"card-body text-center start-info rounded\" style=\"color:#ffffff; background-color:#ED1C24;\">\r\n                                                <div class=\"card-title\">\r\n                                                    <h5>Robot-Game Vorrunde 1<\/h5>\r\n                                                <\/div>\r\n                                                <div class=\"card-text\">\r\n                                                    <div class=\"fs-5 fw-bold\">12:00 - 12:35<\/div>[Robot-Game Bereich]\r\n                                                <\/div>\r\n                                            <\/div>\r\n                                        <\/div>\r\n                                    <\/div>\r\n                                   <div class=\"col\" id=\"486990\">\r\n                                        <div class=\"card h-100\">\r\n                                            <div class=\"card-body text-center start-info rounded\" style=\"color:#ffffff; background-color:#00A651;\">\r\n                                                <div class=\"card-title\">\r\n                                                    <h5>Preisverleihung<\/h5>\r\n                                                <\/div>\r\n                                                <div class=\"card-text\">\r\n                                                    <div class=\"fs-5 fw-bold\">12:40 - 13:10<\/div>[Preisverleihung]\r\n                                                <\/div>\r\n                                            <\/div>\r\n                                        <\/div>\r\n                                    <\/div>\r\n                                   <div class=\"col\" id=\"486991\">\r\n                                        <div class=\"card h-100\">\r\n                                            <div class=\"card-body text-center start-info rounded\" style=\"color:#ffffff; background-color:#ED1C24;\">\r\n                                                <div class=\"card-title\">\r\n                                                    <h5>Jury-Runde<\/h5>\r\n                                                <\/div>\r\n                                                <div class=\"card-text\">\r\n                                                    <div class=\"fs-5 fw-bold\">13:05 - 13:40<\/div>[Jurybewertung 1]<br>[Jurybewertung 2]<br>[Jurybewertung 3]\r\n                                                <\/div>\r\n                                            <\/div>\r\n                                        <\/div>\r\n                                    <\/div>\r\n                                   <div class=\"col\" id=\"486992\">\r\n                                        <div class=\"card h-100\">\r\n                                            <div class=\"card-body text-center start-info rounded\" style=\"color:#ffffff; background-color:#ED1C24;\">\r\n                                                <div class=\"card-title\">\r\n                                                    <h5>Robot-Game Vorrunde 2<\/h5>\r\n                                                <\/div>\r\n                                                <div class=\"card-text\">\r\n                                                    <div class=\"fs-5 fw-bold\">13:50 - 14:25<\/div>[Robot-Game Bereich]\r\n                                                <\/div>\r\n                                            <\/div>\r\n                                        <\/div>\r\n                                    <\/div>\r\n                                   \r\n    <\/div>\r\n<\/div>\r\n\r\n<div class=\"container\" style=\"margin-top:10px\">\r\n    &nbsp;\r\n<\/div>\r\n\r\n\r\n\t\t\t\t\r\n\t\t\t<\/div>\t\r\n\t\t<\/main>\r\n\t\r\n\r\n<\/body><\/html>";

function buildPointInTimeParam() {
  return { point_in_time: getFormattedDateTime() };
}

async function callNow() {
  loading.value = true
  result.value = null
  try {
    const params = buildPointInTimeParam()
    const { data } = await axios.get(`/plans/action-now/${props.content.planId}`, { params })
    result.value = data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  callNow();
  setInterval(callNow, 5 * 60 * 1000); // every 5 minutes
})

</script>

<template>
  <!-- <iframe :srcDoc="data" /> -->
  <object :data="planUrl"/>
  <FabricSlideContentRenderer v-if="props.content.background" class="background" :content="props.content"
                              :preview="props.preview"></FabricSlideContentRenderer>
  /
</template>

<style scoped>
iframe, object {
  width: 100%;
  height: 100%;
  margin: 0;
  position: relative;
  overflow: hidden;
  background: transparent;
}

.background {
  position: absolute;
  top: 0;
  z-index: -10;
}
</style>