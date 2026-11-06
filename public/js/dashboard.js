(function () {
  var data = window.RONDINO_CHARTS;
  if (!data || typeof Chart === 'undefined') return;

  var grid = 'rgba(30, 41, 51, 0.08)';
  var tick = '#5c6b7a';
  Chart.defaults.font.family = 'Vazirmatn, Tahoma, sans-serif';
  Chart.defaults.color = tick;

  function baseOpts(stacked) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', rtl: true, labels: { boxWidth: 12 } } },
      scales: {
        x: { stacked: !!stacked, grid: { color: grid }, ticks: { maxRotation: 45, minRotation: 0 } },
        y: { stacked: !!stacked, beginAtZero: true, grid: { color: grid } }
      }
    };
  }

  var elList = document.getElementById('chart-listings');
  if (elList) {
    new Chart(elList, {
      type: 'bar',
      data: {
        labels: data.bySource.labels,
        datasets: [{
          label: 'آگهی',
          data: data.bySource.listings,
          backgroundColor: 'rgba(15, 118, 110, 0.75)',
          borderRadius: 4
        }]
      },
      options: baseOpts(false)
    });
  }

  var elContact = document.getElementById('chart-contacts');
  if (elContact) {
    new Chart(elContact, {
      type: 'bar',
      data: {
        labels: data.contactProg.labels,
        datasets: [
          { label: 'با تماس', data: data.contactProg.verified, backgroundColor: 'rgba(15, 118, 110, 0.8)', borderRadius: 4 },
          { label: 'بدون تماس', data: data.contactProg.pending, backgroundColor: 'rgba(148, 163, 184, 0.7)', borderRadius: 4 }
        ]
      },
      options: baseOpts(true)
    });
  }

  var elPrice = document.getElementById('chart-price');
  if (elPrice) {
    new Chart(elPrice, {
      type: 'bar',
      data: {
        labels: data.priceDist.labels,
        datasets: [{
          label: 'تعداد آگهی',
          data: data.priceDist.counts,
          backgroundColor: 'rgba(30, 64, 97, 0.75)',
          borderRadius: 4
        }]
      },
      options: baseOpts(false)
    });
  }

  var elGrowth = document.getElementById('chart-growth');
  if (elGrowth) {
    new Chart(elGrowth, {
      type: 'line',
      data: {
        labels: data.growth.labels,
        datasets: [{
          label: 'آگهی جدید',
          data: data.growth.counts,
          borderColor: '#0f766e',
          backgroundColor: 'rgba(15, 118, 110, 0.15)',
          fill: true,
          tension: 0.3,
          pointRadius: 2
        }]
      },
      options: baseOpts(false)
    });
  }
})();
