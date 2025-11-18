<template>
  <div class="timeline-chart-container">
    <div v-if="loading" class="text-gray-500 py-4">Loading timeline data...</div>
    <div v-else-if="error" class="text-red-500 py-4">{{ error }}</div>
    <div v-else-if="chartData" class="chart-wrapper">
      <canvas ref="chartCanvas"></canvas>
    </div>
    <div v-else class="text-gray-500 py-4">No data available</div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { Chart, registerables } from 'chart.js'
import axios from 'axios'

Chart.register(...registerables)

const props = defineProps<{
  planId: number
}>()

const chartCanvas = ref<HTMLCanvasElement | null>(null)
const chart = ref<Chart | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const chartData = ref<any>(null)

async function loadTimelineData() {
  if (!props.planId) return

  // Destroy existing chart if any
  if (chart.value) {
    chart.value.destroy()
    chart.value = null
  }

  loading.value = true
  error.value = null
  chartData.value = null // Clear previous data

  try {
    const response = await axios.get(`/stats/timeline/${props.planId}`)
    chartData.value = response.data
    // Wait for DOM to update and canvas to be rendered
    await nextTick()
    // Wait for multiple ticks to ensure Safari has rendered the canvas
    await new Promise(resolve => setTimeout(resolve, 150))
    // Try to update chart, but watch on canvas will also trigger if needed
    // Only if canvas is ready and chart doesn't exist
    if (chartCanvas.value && !chart.value) {
      updateChart()
    }
  } catch (e: any) {
    error.value = e.response?.data?.error || 'Failed to load timeline data'
    console.error('Timeline data error:', e)
  } finally {
    loading.value = false
  }
}

function updateChart() {
  // Prevent duplicate chart creation
  if (chart.value) {
    console.log('Chart already exists, skipping update')
    return
  }

  if (!chartCanvas.value || !chartData.value) {
    console.warn('Cannot update chart: canvas or data missing', {
      canvas: !!chartCanvas.value,
      data: !!chartData.value
    })
    // If canvas is not ready, wait a bit and retry
    if (chartData.value && !chartCanvas.value) {
      setTimeout(() => {
        if (chartCanvas.value && chartData.value && !chart.value) {
          updateChart()
        }
      }, 100)
    }
    return
  }

  // Ensure canvas is actually in the DOM
  if (!chartCanvas.value.isConnected) {
    console.warn('Canvas is not connected to DOM, waiting...')
    setTimeout(() => {
      if (chartCanvas.value && chartData.value && !chart.value) {
        updateChart()
      }
    }, 100)
    return
  }

  // Check if canvas has dimensions - use requestAnimationFrame to ensure DOM is ready
  requestAnimationFrame(() => {
    if (!chartCanvas.value || chart.value) return
    
    const rect = chartCanvas.value.getBoundingClientRect()
    if (rect.width === 0 || rect.height === 0) {
      console.warn('Canvas has zero dimensions, retrying...', rect)
      // Retry after a short delay
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

  // Destroy existing chart if it exists
  if (chart.value) {
    chart.value.destroy()
    chart.value = null
  }

  const { daily_data, publication_intervals, start_date, end_date } = chartData.value
  
  if (!daily_data || daily_data.length === 0) {
    console.warn('No daily data available', chartData.value)
    return
  }

  console.log('Chart data:', {
    daily_data_count: daily_data.length,
    publication_intervals_count: publication_intervals?.length || 0,
    start_date,
    end_date
  })

  // Prepare labels (dates)
  const labels = daily_data.map((d: any) => d.date)

  // Prepare generator runs data (bars, left y-axis)
  const generatorRunsData = daily_data.map((d: any) => d.generator_runs)
  const maxGeneratorRuns = Math.max(...generatorRunsData, 1)

  // Prepare publication level data (horizontal lines, right y-axis)
  // We need to create a dataset that shows horizontal lines for each interval
  const publicationDatasets = (publication_intervals || []).map((interval: any) => {
    // Create data points for this interval
    const intervalData = labels.map((label: string) => {
      const labelDate = new Date(label + 'T00:00:00')
      const startDate = new Date(interval.start_date + 'T00:00:00')
      const endDate = new Date(interval.end_date + 'T00:00:00')
      
      // Check if this date falls within the interval (inclusive)
      if (labelDate >= startDate && labelDate <= endDate) {
        return interval.level
      }
      return null
    })

    return {
      label: `Publication Level ${interval.level}`,
      data: intervalData,
      borderColor: getLevelColor(interval.level),
      backgroundColor: getLevelColor(interval.level, 0.2),
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

  console.log('Creating chart with:', {
    canvas: !!chartCanvas.value,
    labels_count: labels.length,
    generatorRuns_count: generatorRunsData.length,
    publicationDatasets_count: publicationDatasets.length
  })

  try {
    chart.value = new Chart(chartCanvas.value, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Generator Runs',
            data: generatorRunsData,
            backgroundColor: 'rgba(54, 162, 235, 0.6)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1,
            yAxisID: 'y',
          },
          ...publicationDatasets,
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
          display: true,
          position: 'top' as const,
        },
        tooltip: {
          callbacks: {
            title: (items) => {
              return `Date: ${items[0].label}`
            },
            label: (context) => {
              if (context.datasetIndex === 0) {
                return `Generator Runs: ${context.parsed.y}`
              }
              const dataset = context.dataset
              if (dataset.label?.includes('Publication Level')) {
                return `${dataset.label}: ${context.parsed.y ?? 'N/A'}`
              }
              return ''
            },
          },
        },
      },
      scales: {
        x: {
          type: 'category',
          title: {
            display: true,
            text: 'Date',
          },
        },
        y: {
          type: 'linear',
          position: 'left',
          title: {
            display: true,
            text: 'Generator Runs',
          },
          beginAtZero: true,
          max: maxGeneratorRuns > 0 ? Math.ceil(maxGeneratorRuns * 1.1) : 1,
        },
        y1: {
          type: 'linear',
          position: 'right',
          title: {
            display: true,
            text: 'Publication Level',
          },
          min: 0,
          max: 4,
          ticks: {
            stepSize: 1,
          },
          grid: {
            drawOnChartArea: false,
          },
        },
      },
    },
    })
    console.log('Chart created successfully')
  } catch (err) {
    console.error('Error creating chart:', err)
    error.value = 'Failed to render chart'
  }
}

function getLevelColor(level: number, alpha: number = 1): string {
  const colors: Record<number, string> = {
    1: `rgba(34, 197, 94, ${alpha})`,   // green
    2: `rgba(234, 179, 8, ${alpha})`,   // yellow
    3: `rgba(249, 115, 22, ${alpha})`,  // orange
    4: `rgba(239, 68, 68, ${alpha})`,   // red
  }
  return colors[level] || `rgba(128, 128, 128, ${alpha})`
}

// Watch for when canvas becomes available
watch(chartCanvas, async (newCanvas, oldCanvas) => {
  // Only trigger when canvas changes from null to a value and chart doesn't exist
  if (newCanvas && !oldCanvas && chartData.value && !chart.value) {
    // Canvas is now available, wait a bit for DOM to settle
    await nextTick()
    await new Promise(resolve => setTimeout(resolve, 150))
    // Only create if still needed
    if (chartCanvas.value && chartData.value && !chart.value && chartCanvas.value.isConnected) {
      updateChart()
    }
  }
}, { immediate: false })

watch(() => props.planId, () => {
  loadTimelineData()
})

onMounted(() => {
  loadTimelineData()
})

onBeforeUnmount(() => {
  if (chart.value) {
    chart.value.destroy()
  }
})
</script>

<style scoped>
.timeline-chart-container {
  width: 100%;
  padding: 1rem;
}

.chart-wrapper {
  position: relative;
  height: 400px;
  width: 100%;
}
</style>

