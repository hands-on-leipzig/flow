<template>
  <div class="access-chart-container">
    <div class="mb-4 flex justify-center gap-2 items-center">
      <button
        @click="viewMode = 'timeline'"
        :class="[
          'px-4 py-2 rounded transition-colors',
          viewMode === 'timeline' 
            ? 'bg-blue-500 text-white hover:bg-blue-600' 
            : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
        ]"
      >
        Vollständige Timeline
      </button>
      <button
        @click="viewMode = 'day'"
        :class="[
          'px-4 py-2 rounded transition-colors',
          viewMode === 'day' 
            ? 'bg-blue-500 text-white hover:bg-blue-600' 
            : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
        ]"
      >
        Tag des Events{{ formattedEventDate ? ` (${formattedEventDate})` : '' }}
      </button>
    </div>
    
    <div v-if="loading" class="text-gray-500 py-4">Lade Daten...</div>
    <div v-else-if="error" class="text-red-500 py-4">{{ error }}</div>
    <div v-else-if="chartData" class="chart-wrapper">
      <canvas ref="chartCanvas"></canvas>
    </div>
    <div v-else class="text-gray-500 py-4">Keine Daten verfügbar</div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick, computed } from 'vue'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

const props = defineProps<{
  eventId: number
}>()

const chartCanvas = ref<HTMLCanvasElement | null>(null)
const chart = ref<Chart | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const chartData = ref<any>(null)
const viewMode = ref<'timeline' | 'day'>('timeline')

// Format event date as DD.MM.YYYY
const formattedEventDate = computed(() => {
  if (!chartData.value?.event_date) return ''
  const date = new Date(chartData.value.event_date + 'T00:00:00')
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const year = date.getFullYear()
  return `${day}.${month}.${year}`
})

async function loadAccessData() {
  if (!props.eventId) return

  // Destroy existing chart if any
  if (chart.value) {
    chart.value.destroy()
    chart.value = null
  }

  loading.value = true
  error.value = null
  chartData.value = null

  try {
    const response = await axios.get(`/stats/one-link-access/${props.eventId}`)
    chartData.value = response.data
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 150))
    if (chartCanvas.value && !chart.value) {
      updateChart()
    }
  } catch (e: any) {
    error.value = e.response?.data?.error || 'Failed to load access data'
    console.error('Access data error:', e)
  } finally {
    loading.value = false
  }
}

function updateChart() {
  if (chart.value) {
    console.log('Chart already exists, skipping update')
    return
  }

  if (!chartCanvas.value || !chartData.value) {
    if (chartData.value && !chartCanvas.value) {
      setTimeout(() => {
        if (chartCanvas.value && chartData.value && !chart.value) {
          updateChart()
        }
      }, 100)
    }
    return
  }

  if (!chartCanvas.value.isConnected) {
    setTimeout(() => {
      if (chartCanvas.value && chartData.value && !chart.value) {
        updateChart()
      }
    }, 100)
    return
  }

  requestAnimationFrame(() => {
    if (!chartCanvas.value || chart.value) return
    
    const rect = chartCanvas.value.getBoundingClientRect()
    if (rect.width === 0 || rect.height === 0) {
      setTimeout(() => {
        if (chartCanvas.value && chartData.value && !chart.value) {
          updateChart()
        }
      }, 200)
      return
    }
    createChart()
  })
}

function createChart() {
  if (!chartCanvas.value || !chartData.value) {
    return
  }

  if (chart.value) {
    chart.value.destroy()
    chart.value = null
  }

  if (viewMode.value === 'timeline') {
    createTimelineChart()
  } else {
    createDayChart()
  }
}

function createTimelineChart() {
  if (!chartCanvas.value || !chartData.value) return

  const { daily_data, publication_intervals, start_date, end_date } = chartData.value
  
  if (!daily_data || daily_data.length === 0) {
    return
  }

  // Format date labels to dd.mm.yyyy
  const formatDateLabel = (dateStr: string): string => {
    const date = new Date(dateStr + 'T00:00:00')
    const day = String(date.getDate()).padStart(2, '0')
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const year = date.getFullYear()
    return `${day}.${month}.${year}`
  }
  
  const labels = daily_data.map((d: any) => formatDateLabel(d.date))
  
  // Get today's date in Y-m-d format for comparison
  const today = new Date()
  const todayStr = today.toISOString().split('T')[0]
  const todayFormatted = formatDateLabel(todayStr)
  const todayIndex = labels.indexOf(todayFormatted)
  
  const accessData = daily_data.map((d: any) => d.access_count)
  const maxAccess = Math.max(...accessData, 1)

  const publicationDatasets = (publication_intervals || []).map((interval: any) => {
    // Map formatted labels back to original dates for comparison
    const intervalData = daily_data.map((d: any) => {
      const labelDate = new Date(d.date + 'T00:00:00')
      const startDate = new Date(interval.start_date + 'T00:00:00')
      const endDate = new Date(interval.end_date + 'T00:00:00')
      
      if (labelDate >= startDate && labelDate <= endDate) {
        return interval.level
      }
      return null
    })

    return {
      label: `Level der Veröffentlichung ${interval.level}`,
      data: intervalData,
      borderColor: 'rgba(59, 130, 246, 1)',
      backgroundColor: 'rgba(59, 130, 246, 0.2)',
      borderWidth: 3,
      fill: false,
      tension: 0,
      pointRadius: 0,
      pointHoverRadius: 0,
      spanGaps: true,
      yAxisID: 'y1',
      type: 'line' as const,
    }
  })

  // Register custom plugin for today line (only if today is in range)
  const todayLinePlugin = todayIndex >= 0 ? {
    id: 'todayLine',
    afterDraw: (chart: any) => {
      const ctx = chart.ctx
      const xScale = chart.scales.x
      const yScale = chart.scales.y
      
      if (!xScale || !yScale) return
      
      // Get the x position of today (using formatted label)
      const todayX = xScale.getPixelForValue(todayFormatted)
      
      if (isNaN(todayX)) return
      
      // Draw vertical line
      ctx.save()
      ctx.strokeStyle = 'rgba(239, 68, 68, 1)' // red-500
      ctx.lineWidth = 2
      ctx.setLineDash([5, 5])
      ctx.beginPath()
      ctx.moveTo(todayX, yScale.top)
      ctx.lineTo(todayX, yScale.bottom)
      ctx.stroke()
      ctx.restore()
      
      // Draw label
      ctx.save()
      ctx.fillStyle = 'rgba(239, 68, 68, 0.8)'
      ctx.font = 'bold 12px sans-serif'
      ctx.textAlign = 'center'
      ctx.textBaseline = 'top'
      const labelY = yScale.top + 5
      ctx.fillText('Heute', todayX, labelY)
      ctx.restore()
    }
  } : null

  try {
    chart.value = new Chart(chartCanvas.value, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Zugriffe',
            data: accessData,
            backgroundColor: 'rgba(255, 159, 64, 0.6)',
            borderColor: 'rgba(255, 159, 64, 1)',
            borderWidth: 1,
            yAxisID: 'y',
          },
          ...publicationDatasets,
        ],
      },
      plugins: todayLinePlugin ? [todayLinePlugin] : [],
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index' as const,
          intersect: false,
        },
        plugins: {
          legend: {
            display: false,
          },
        },
        scales: {
          x: {
            type: 'category',
            title: {
              display: false,
            },
          },
          y: {
            type: 'linear',
            position: 'left',
            title: {
              display: true,
              text: 'Zugriffe',
            },
            beginAtZero: true,
            max: maxAccess > 0 ? Math.ceil(maxAccess * 1.1) : 1,
            ticks: {
              stepSize: 1,
              precision: 0,
            },
          },
          y1: {
            type: 'linear',
            position: 'right',
            title: {
              display: true,
              text: 'Level der Veröffentlichung',
            },
            min: 0,
            max: 4,
            ticks: {
              stepSize: 1,
              precision: 0,
            },
            grid: {
              drawOnChartArea: false,
            },
          },
        },
      },
    })
  } catch (err) {
    console.error('Error creating timeline chart:', err)
    error.value = 'Failed to render chart'
  }
}

function createDayChart() {
  if (!chartCanvas.value || !chartData.value) return

  const { event_day_intervals } = chartData.value
  
  if (!event_day_intervals || event_day_intervals.length === 0) {
    return
  }

  const labels = event_day_intervals.map((d: any) => d.time)
  const accessData = event_day_intervals.map((d: any) => d.access_count)
  const maxAccess = Math.max(...accessData, 1)

  try {
    chart.value = new Chart(chartCanvas.value, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Zugriffe',
            data: accessData,
            backgroundColor: 'rgba(255, 159, 64, 0.6)',
            borderColor: 'rgba(255, 159, 64, 1)',
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index' as const,
          intersect: false,
        },
        plugins: {
          legend: {
            display: false,
          },
        },
        scales: {
          x: {
            type: 'category',
            title: {
              display: false,
            },
            ticks: {
              maxRotation: 45,
              minRotation: 45,
            },
          },
          y: {
            type: 'linear',
            position: 'left',
            title: {
              display: true,
              text: 'Zugriffe',
            },
            beginAtZero: true,
            max: maxAccess > 0 ? Math.ceil(maxAccess * 1.1) : 1,
            ticks: {
              stepSize: 1,
              precision: 0,
            },
          },
        },
      },
    })
  } catch (err) {
    console.error('Error creating day chart:', err)
    error.value = 'Failed to render chart'
  }
}

watch(chartCanvas, async (newCanvas, oldCanvas) => {
  if (newCanvas && !oldCanvas && chartData.value && !chart.value) {
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 150))
    if (chartCanvas.value && chartData.value && !chart.value && chartCanvas.value.isConnected) {
      updateChart()
    }
  }
}, { immediate: false })

watch(() => props.eventId, () => {
  loadAccessData()
})

watch(viewMode, () => {
  if (chartData.value) {
    if (chart.value) {
      chart.value.destroy()
      chart.value = null
    }
    updateChart()
  }
})

onMounted(() => {
  loadAccessData()
})

onBeforeUnmount(() => {
  if (chart.value) {
    chart.value.destroy()
  }
})
</script>

<style scoped>
.access-chart-container {
  width: 100%;
  padding: 1rem;
}

.chart-wrapper {
  position: relative;
  height: 400px;
  width: 100%;
}
</style>

