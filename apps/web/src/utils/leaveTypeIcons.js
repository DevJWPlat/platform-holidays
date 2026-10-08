import * as icons from '@fortawesome/free-solid-svg-icons'

const fallback = icons.faCalendarDay

const make = (key, label, exportName) => ({
  key,
  label,
  icon: icons[exportName] || fallback,
})

export const leaveTypeIconChoices = [
  make('umbrella-beach', 'Holiday', 'faUmbrellaBeach'),
  make('baby-carriage', 'Maternity / paternity', 'faBabyCarriage'),
  make('cake-candles', 'Birthday', 'faCakeCandles'),
  make('kit-medical', 'Sick', 'faKitMedical'),
  make('clock', 'Leave', 'faClock'),
  make('snowflake', 'Festive break', 'faSnowflake'),
  make('seedling', 'Training', 'faSeedling'),
  make('people-group', 'Team day', 'faPeopleGroup'),
  make('plane-departure', 'Client meetings', 'faPlaneDeparture'),
  make('building', 'In Manchester', 'faBuilding'),
  make('paw', 'In Cheshire / pet', 'faPaw'),
  make('calendar-day', 'Calendar', 'faCalendarDay'),
  make('calendar-check', 'Calendar check', 'faCalendarCheck'),
  make('plane', 'Plane', 'faPlane'),
  make('user-doctor', 'Doctor', 'faUserDoctor'),
  make('heart', 'Compassionate', 'faHeart'),
  make('users', 'Family', 'faUsers'),
  make('child', 'Child', 'faChild'),
  make('graduation-cap', 'Education', 'faGraduationCap'),
  make('briefcase', 'Work', 'faBriefcase'),
  make('house', 'Home', 'faHouse'),
  make('car', 'Car', 'faCar'),
  make('person-walking', 'Personal', 'faPersonWalking'),
  make('mug-hot', 'Break', 'faMugHot'),
  make('suitcase', 'Business travel', 'faSuitcase'),
  make('location-dot', 'Location', 'faLocationDot'),
  make('laptop', 'Remote work', 'faLaptop'),
  make('leaf', 'Wellbeing', 'faLeaf'),
  make('dove', 'Bereavement', 'faDove'),
  make('star', 'Special leave', 'faStar'),
  make('compass', 'Off site', 'faCompass'),
  make('dog', 'Pet care', 'faDog'),
  make('person', 'Personal leave', 'faPerson'),
  make('hospital', 'Hospital', 'faHospital'),
  make('house-medical', 'Medical at home', 'faHouseMedical'),
  make('person-pregnant', 'Pregnancy', 'faPersonPregnant'),
  make('hands-holding-child', 'Family care', 'faHandsHoldingChild'),
  make('champagne-glasses', 'Celebration', 'faChampagneGlasses'),
  make('gift', 'Birthday / special day', 'faGift'),
  make('train', 'Train travel', 'faTrain'),
  make('taxi', 'Taxi / travel', 'faTaxi'),
  make('building', 'Office', 'faBuilding'),
]

const iconMap = Object.fromEntries(
  leaveTypeIconChoices.map((item) => [item.key, item.icon]),
)

export function getLeaveTypeIcon(key) {
  return iconMap[key] || fallback
}
